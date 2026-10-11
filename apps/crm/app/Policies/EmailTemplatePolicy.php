<?php

namespace App\Policies;

use App\Models\EmailTemplate;
use App\Models\User;

/**
 * Who may do what to an email template.
 *
 * Permissions only. Any state rule about a template would belong in the
 * controller for the same reason it does on campaigns: Super Admin
 * short-circuits every policy through the Gate::before in AppServiceProvider,
 * so an invariant expressed here is skipped for the account most able to do
 * damage with it.
 *
 * Auto-discovered by Laravel's naming convention.
 */
class EmailTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('email.templates.view');
    }

    public function view(User $user, EmailTemplate $template): bool
    {
        return $user->can('email.templates.view');
    }

    public function create(User $user): bool
    {
        return $user->can('email.templates.create');
    }

    public function update(User $user, ?EmailTemplate $template = null): bool
    {
        return $user->can('email.templates.edit');
    }

    public function delete(User $user, ?EmailTemplate $template = null): bool
    {
        return $user->can('email.templates.delete');
    }
}
