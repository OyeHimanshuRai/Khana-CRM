<?php

namespace App\Http\Controllers\Bills;

use App\Models\Expense;
use App\Rules\Amount;
use App\Services\Purchasing;
use App\Support\Here;
use App\Support\Units;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Expenses: money spent on something that is not stock - gas, rent, a
 * plumber. One line to add, and the list underneath.
 */
final class ExpenseController
{
    public function index(Request $request): View
    {
        $outlet = Here::outlet();
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) === 1
            ? $request->query('month')
            : substr(Here::businessDate(), 0, 7);

        $inMonth = Expense::forOutlet($outlet)
            ->whereDate('spent_on', '>=', $month.'-01')
            ->whereDate('spent_on', '<=', date('Y-m-t', strtotime($month.'-01')));

        return view('expenses.index', [
            'month' => $month,
            'today' => Here::businessDate(),
            'categories' => Expense::CATEGORIES,
            'methods' => Expense::METHODS,
            'total' => (int) (clone $inMonth)->sum('amount_paise'),
            'byCategory' => (clone $inMonth)->groupBy('category')->selectRaw('category, SUM(amount_paise) as total')->orderByDesc('total')->pluck('total', 'category'),
            'expenses' => $inMonth->latest('spent_on')->latest('id')->paginate(50)->withQueryString(),
        ]);
    }

    public function store(Request $request, Purchasing $purchasing): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', Rule::in(array_keys(Expense::CATEGORIES))],
            'amount' => ['required', new Amount],
            'spent_on' => ['required', 'date'],
            'method' => ['required', Rule::in(array_keys(Expense::METHODS))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $purchasing->spend(Here::outlet(), $data['category'], Units::paise($data['amount']),
            date('Y-m-d', strtotime($data['spent_on'])), $data['method'], $data['note'] ?? null);

        return redirect()->route('expenses.index')->with('status', 'Expense recorded.');
    }
}
