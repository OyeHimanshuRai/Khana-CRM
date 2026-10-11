<?php

namespace App\Http\Requests\Admin;

use App\Models\EmailTemplate;

class UpdateEmailTemplateRequest extends EmailTemplateRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->subject()) ?? false;
    }

    /**
     * The bound model from the route, so `slug` may keep its own value.
     */
    protected function subject(): ?EmailTemplate
    {
        $template = $this->route('template');

        return $template instanceof EmailTemplate ? $template : null;
    }
}
