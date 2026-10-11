<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * An amount of money as people type it: "1850", "1,850.50".
 *
 * Laravel's `numeric` refuses the comma, and somebody copying a figure off
 * a supplier's bill types the comma. App\Support\Units::paise() reads the
 * same forms this lets through.
 */
final class Amount implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $text = trim((string) $value);

        if (preg_match('/^\d[\d,]*(\.\d{1,2})?$/', $text) !== 1) {
            $fail('Type the amount in rupees, like 1850 or 1,850.50.');

            return;
        }

        if ((float) str_replace(',', '', $text) <= 0) {
            $fail('The amount has to be above zero.');
        }
    }
}
