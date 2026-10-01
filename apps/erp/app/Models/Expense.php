<?php

namespace App\Models;

use App\Models\Concerns\InOutlet;
use Illuminate\Database\Eloquent\Model;

/**
 * Money spent on something that is not stock: gas, rent, a plumber.
 */
class Expense extends Model
{
    use InOutlet;

    public const CATEGORIES = [
        'gas' => 'Gas & fuel',
        'utilities' => 'Electricity & water',
        'rent' => 'Rent',
        'wages' => 'Wages & staff',
        'repairs' => 'Repairs & maintenance',
        'cleaning' => 'Cleaning & supplies',
        'transport' => 'Transport',
        'marketing' => 'Marketing',
        'other' => 'Other',
    ];

    public const METHODS = [
        'cash' => 'Cash',
        'bank' => 'Bank transfer',
        'upi' => 'UPI',
        'drawer' => 'From the till',
    ];

    protected $fillable = ['tenant_id', 'outlet_id', 'category', 'amount_paise', 'spent_on', 'method', 'note', 'user_id'];

    protected function casts(): array
    {
        return ['spent_on' => 'date', 'amount_paise' => 'integer'];
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst(str_replace('_', ' ', $this->category));
    }
}
