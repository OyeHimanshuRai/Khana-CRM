<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Send one preview copy of a template to a named address.
 */
class SendTestEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('template')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            // Blank means "send it to me", which is what an operator almost
            // always wants and saves them typing their own address.
            'email' => $this->filled('email')
                ? mb_strtolower(trim((string) $this->input('email')))
                : $this->user()?->email,
        ]);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Enter an address to send the test to.',
            'email.email' => 'That does not look like a valid email address.',
        ];
    }
}
