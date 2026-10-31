<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\NotificationPreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Profile > Notifications: what I hear about, and how.
 *
 * Every signed-in person's own, like the profile itself, so no permission
 * guards it. It only ever reads and writes the row of whoever is signed in.
 *
 * Only the kinds this person can actually receive are listed: a cashier is
 * not offered a switch for "new sign-up", which would never reach them.
 */
class NotificationPreferenceController extends Controller
{
    public function edit(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('admin.profile.notifications', [
            'prefs' => NotificationPreferences::for($user),
            'types' => $this->typesFor($user),
            'languages' => NotificationPreferences::LANGUAGES,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'voice_lang' => ['nullable', Rule::in(array_keys(NotificationPreferences::LANGUAGES))],
            'volume' => ['nullable', 'integer', 'min:0', 'max:100'],
            'types' => ['nullable', 'array'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $saved = NotificationPreferences::fromInput($request->all());

        // Kinds this person was not shown keep whatever they had, rather than
        // being switched off because the form did not carry a box for them.
        $current = NotificationPreferences::for($user);
        $offered = array_keys($this->typesFor($user));

        foreach ($saved['types'] as $type => $channels) {
            if (! in_array($type, $offered, true)) {
                $saved['types'][$type] = $current['types'][$type];
            }
        }

        $user->forceFill(['notification_preferences' => $saved])->save();

        return json_success('Notification settings saved.', ['prefs' => NotificationPreferences::for($user->fresh())]);
    }

    /**
     * The kinds this person could ever be sent.
     *
     * Judged by the permission each alert is gated on, the same rule the bell
     * uses. Kinds with no gate (low stock, security, a till left open) are
     * shown to everybody; the kitchen ticket to anybody who can see the board.
     *
     * @return array<string, array{label: string, icon: string}>
     */
    private function typesFor(User $user): array
    {
        $gates = [
            NotificationPreferences::KITCHEN_TICKET => 'kitchen.tickets.view',
            \App\Models\Alert::ORDER_PLACED => 'sales.orders.view',
            \App\Models\Alert::FEEDBACK => 'crm.feedback.view',
            \App\Models\Alert::SIGNUP => 'settings.tenants.create',
            \App\Models\Alert::SUBSCRIPTION_PAID => 'settings.subscriptions.edit',
            \App\Models\Alert::RENEWAL_REQUEST => 'settings.subscriptions.edit',
            \App\Models\Alert::DEMO_REQUEST => 'content.demo_requests.view',
        ];

        return array_filter(
            NotificationPreferences::types(),
            fn (string $type) => ! isset($gates[$type]) || $user->can($gates[$type]),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
