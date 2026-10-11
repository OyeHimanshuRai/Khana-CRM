<?php

namespace App\Http\Requests\Admin;

use App\Models\EmailTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared shape of the template create and edit forms.
 */
abstract class EmailTemplateRequest extends FormRequest
{
    abstract protected function subject(): ?EmailTemplate;

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],

            /*
             | Optional on the way in - blank slugs down from the name. The
             | uniqueness check ignores soft-deleted rows deliberately here:
             | unlike a subscriber email, a slug is a label rather than an
             | identity, and a deleted template should not reserve one forever.
             */
            'slug' => [
                'nullable',
                'string',
                'max:220',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/i',
                Rule::unique('email_templates', 'slug')
                    ->ignore($this->subject()?->id)
                    ->whereNull('deleted_at'),
            ],

            'category' => ['nullable', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:400'],

            'subject' => ['required', 'string', 'max:250'],
            'preheader' => ['nullable', 'string', 'max:250'],

            /*
             | Operator-authored HTML. Not sanitised: writing it needs the
             | templates.edit permission, and stripping tags would break the
             | one thing an email body is for. It is never rendered as Blade,
             | and the admin preview loads it in a sandboxed iframe so it
             | cannot reach the panel around it.
             */
            'content' => ['nullable', 'string', 'max:200000'],

            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'Use lowercase letters, numbers and hyphens only.',
            'slug.unique' => 'Another template already uses that handle.',
            'content.max' => 'The content is too long. Keep it under 200,000 characters.',
        ];
    }

    /**
     * The validated payload in the shape the model wants.
     *
     * @return array<string, mixed>
     */
    public function templateAttributes(): array
    {
        $data = $this->validated();
        $slug = $data['slug'] ?? null;

        return [
            'name' => $data['name'],
            // Re-slugged only when the field was filled in, so an edit that
            // leaves it blank keeps the handle something may be calling.
            'slug' => filled($slug)
                ? EmailTemplate::uniqueSlug($slug, $this->subject()?->id)
                : ($this->subject()?->slug ?: EmailTemplate::uniqueSlug($data['name'])),
            'category' => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
            'subject' => $data['subject'],
            'preheader' => $data['preheader'] ?? null,
            'content' => $data['content'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];
    }
}
