<?php

namespace App\Http\Controllers\Sales;

use App\Models\SalesImport;
use App\Models\SalesItem;
use App\Services\SalesImporter;
use App\Support\Here;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * Importing sales from other billing software, in two screens:
 *
 *   1. Choose the file.
 *   2. Check which column is which and what each item name stands for -
 *      both filled in from last time - and import.
 *
 * The uploaded file waits in private storage under a random name between
 * the two, and is deleted once it has been imported.
 */
final class ImportController
{
    public function create(): View
    {
        return view('sales.import', [
            'imports' => SalesImport::forOutlet(Here::outlet())->with('user')->latest('id')->limit(15)->get(),
        ]);
    }

    public function upload(Request $request, SalesImporter $importer): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt'],
        ], ['file.mimes' => 'Choose a CSV file. In Excel, use Save As and pick "CSV".']);

        $token = Str::random(32);
        $upload = $request->file('file');

        Storage::disk('local')->putFileAs('imports', $upload, $token.'.csv');

        try {
            $importer->read(Storage::disk('local')->path('imports/'.$token.'.csv'));
        } catch (RuntimeException $e) {
            Storage::disk('local')->delete('imports/'.$token.'.csv');

            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        // Who uploaded it and what it was called, so only they can finish it.
        $request->session()->put('import.'.$token, ['outlet' => Here::outlet(), 'name' => $upload->getClientOriginalName()]);

        return redirect()->route('sales.import.match', $token);
    }

    public function match(Request $request, string $token, SalesImporter $importer): View
    {
        $outlet = Here::outlet();
        $file = $this->file($request, $token, $importer);

        $layout = $importer->layoutFor($outlet, $file['headers']);

        // The person may have just changed which column is which.
        foreach (array_keys(SalesImporter::FIELDS) as $field) {
            if ($request->has($field)) {
                $layout[$field] = $request->query($field) === '' ? null : (int) $request->query($field);
            }
        }

        $ready = collect(SalesImporter::REQUIRED)->every(fn (string $field) => $layout[$field] !== null);

        return view('sales.match', [
            'token' => $token,
            'fileName' => $request->session()->get('import.'.$token.'.name'),
            'headers' => $file['headers'],
            'sample' => array_slice($file['rows'], 0, 5),
            'rowCount' => count($file['rows']),
            'fields' => SalesImporter::FIELDS,
            'required' => SalesImporter::REQUIRED,
            'layout' => $layout,
            'ready' => $ready,
            'names' => $ready ? $importer->names($outlet, $file['rows'], $layout) : [],
            'dishes' => SalesItem::forOutlet($outlet)->active()->with(['sizes' => fn ($q) => $q->where('is_active', true)])->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, string $token, SalesImporter $importer): RedirectResponse
    {
        $outlet = Here::outlet();
        $file = $this->file($request, $token, $importer);

        $layout = [];

        foreach (array_keys(SalesImporter::FIELDS) as $field) {
            $layout[$field] = $request->filled('layout.'.$field) ? (int) $request->input('layout.'.$field) : null;
        }

        // "12" is a dish; "12:34" is one size of it; "" is "make a new one".
        $matches = [];

        foreach ((array) $request->input('match', []) as $row) {
            [$itemId, $sizeId] = array_pad(explode(':', (string) ($row['choice'] ?? '')), 2, 0);
            $matches[(string) ($row['alias'] ?? '')] = ['sales_item_id' => $itemId === '' ? null : (int) $itemId, 'sales_item_size_id' => (int) $sizeId];
        }

        try {
            $import = $importer->import($outlet, (string) $request->session()->get('import.'.$token.'.name', 'sales.csv'),
                $file['headers'], $file['rows'], $layout, $matches);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        Storage::disk('local')->delete('imports/'.$token.'.csv');
        $request->session()->forget('import.'.$token);

        $message = $import->sales.' sale'.($import->sales === 1 ? '' : 's').' imported from '.$import->rows.' rows.';

        if ($import->skipped > 0) {
            $message .= ' '.$import->skipped.' already here from an earlier import, left as they were.';
        }

        return redirect()->route('sales.index')->with('status', $message);
    }

    /**
     * The uploaded file, if this session uploaded it for this outlet.
     *
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    private function file(Request $request, string $token, SalesImporter $importer): array
    {
        $mine = $request->session()->get('import.'.$token);
        $path = 'imports/'.$token.'.csv';

        abort_unless(is_array($mine) && $mine['outlet'] === Here::outlet() && Storage::disk('local')->exists($path), 404);

        return $importer->read(Storage::disk('local')->path($path));
    }
}
