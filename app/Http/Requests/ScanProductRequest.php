<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScanProductRequest extends FormRequest
{
    /**
     * Route middleware (auth + permission:pos.terminal.view) already gates
     * access; this only guards against a request that somehow reaches the
     * controller without going through it.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // A barcode/SKU is never this long; the cap exists to keep a
            // malformed camera read or a pasted essay from reaching the
            // database at all.
            'code' => ['required', 'string', 'max:190'],
        ];
    }
}
