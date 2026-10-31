<?php

namespace App\Services;

use App\Mail\EmailTemplateTestMail;
use App\Models\ActivityLog;
use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Support\EmailLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Previewing, testing and using an email template.
 *
 * Preview and test send go through one renderer, `render()`, so what the
 * operator is shown on screen is the same string that reaches an inbox. A
 * preview built from a second rendering path is a preview of something
 * nobody will ever receive.
 */
class EmailTemplateService
{
    /** What the merge tags resolve to in a preview or a test send. */
    private const SAMPLE = [
        'name' => 'Sample Recipient',
        'email' => 'sample@example.com',
    ];

    /* ------------------------------------------------------------ preview */

    /**
     * The template as a finished email, ready to drop into an iframe.
     */
    public function preview(EmailTemplate $template): string
    {
        return $this->asDocument($this->render($template), $template->name);
    }

    /**
     * The template's body with its merge tags filled in.
     *
     * The one place substitution happens. `$email` is the address the mail is
     * actually going to on a test send, so the operator sees their own address
     * where a recipient would see theirs.
     */
    public function render(EmailTemplate $template, ?string $email = null): string
    {
        return strtr((string) $template->content, [
            '{{name}}' => e(self::SAMPLE['name']),
            '{{email}}' => e($email ?: self::SAMPLE['email']),
            '{{company}}' => e(Setting::get('company_name') ?: config('app.name')),
        ]);
    }

    /**
     * Give the rendered email a document around it.
     *
     * Template content is a body fragment - which is correct for email, where
     * a client supplies the document - but a browser being handed one over
     * HTTP has to guess at the encoding. An accented name rendering as
     * mojibake in the preview and correctly in the inbox would be a very
     * confusing bug to chase.
     */
    private function asDocument(string $html, string $title): string
    {
        if (str_contains($html, '<html')) {
            return $html;
        }

        return '<!DOCTYPE html><html lang="'.e(str_replace('_', '-', app()->getLocale())).'"><head>'
            .'<meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="robots" content="noindex, nofollow">'
            .'<title>'.e($title).'</title>'
            .'</head><body style="margin:0">'.$html.'</body></html>';
    }

    /**
     * Send one preview copy to an address the operator names.
     *
     * Never throws: a broken mail configuration is a message to show, not a
     * 500 on the template screen.
     */
    public function sendTest(EmailTemplate $template, string $email): bool
    {
        $html = $this->asDocument($this->render($template, $email), $template->name);

        try {
            Mail::to($email)->send(new EmailTemplateTestMail($template, $html));
        } catch (\Throwable $e) {
            // Laravel fires no event for a failed send, so the email_logs row
            // this opened has to be closed by hand or it sits pending.
            EmailLogger::fail($e);

            Log::warning('Template test email failed', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $this->markUsed($template);

        ActivityLog::record(
            'email_template.tested',
            "Sent a test of template \"{$template->name}\" to {$email}",
            $template,
        );

        return true;
    }

    /* ---------------------------------------------------------- duplicate */

    public function duplicate(EmailTemplate $template, ?User $author = null): EmailTemplate
    {
        $copy = new EmailTemplate($template->only([
            'category', 'description', 'subject', 'preheader', 'content',
        ]));

        $copy->name = Str::limit($template->name.' (copy)', 200, '');
        $copy->slug = EmailTemplate::uniqueSlug($copy->name);
        $copy->is_active = false;

        $copy->forceFill([
            'created_by' => $author?->id,
            'created_by_name' => $author?->name,
            // A copy has not been used for anything yet.
            'usage_count' => 0,
            'last_used_at' => null,
        ])->save();

        ActivityLog::record(
            'email_template.duplicated',
            "Duplicated template \"{$template->name}\"",
            $copy,
            ['from' => $template->id],
        );

        return $copy;
    }

    public function markUsed(EmailTemplate $template): void
    {
        $template->forceFill([
            'usage_count' => (int) $template->usage_count + 1,
            'last_used_at' => now(),
        ])->save();
    }

    /* -------------------------------------------------------------- stats */

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        return [
            'total' => EmailTemplate::count(),
            'active' => EmailTemplate::active()->count(),
            'categories' => EmailTemplate::categories()->count(),
            'used' => (int) EmailTemplate::sum('usage_count'),
        ];
    }
}
