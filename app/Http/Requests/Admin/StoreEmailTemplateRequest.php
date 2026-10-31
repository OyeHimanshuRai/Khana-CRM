<?php

namespace App\Http\Requests\Admin;

use App\Models\EmailTemplate;

class StoreEmailTemplateRequest extends EmailTemplateRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', EmailTemplate::class) ?? false;
    }

    protected function subject(): ?EmailTemplate
    {
        return null;
    }
}
