<?php

namespace App\Http\Requests\Shop;

use App\Models\Shop;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A new storefront account. At least one of mobile/email is required so a
 * customer can always be found again at login - the same near-key the
 * counter itself uses (Customer::scopeSearch()).
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Shop $shop */
        $shop = $this->route('shop');

        return [
            'name' => ['required', 'string', 'max:150'],
            'mobile' => [
                'nullable', 'string', 'max:20', 'regex:/^[0-9+\-\s()]{6,20}$/',
                Rule::unique('customers', 'mobile')->where('shop_id', $shop->id),
            ],
            'email' => [
                'nullable', 'string', 'email', 'max:150',
                Rule::unique('customers', 'email')->where('shop_id', $shop->id),
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'mobile.regex' => 'Use digits, spaces, brackets, + or - only.',
            'mobile.unique' => 'An account with this mobile number already exists.',
            'email.unique' => 'An account with this email already exists.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (blank($this->input('mobile')) && blank($this->input('email'))) {
                $validator->errors()->add('mobile', 'Enter a mobile number or an email address.');
            }
        });
    }
}
