<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\ApiAbilities;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * API tokens, for integrations (SRS 2, 21).
 *
 * ---------------------------------------------------------------------------
 * Shown once, and only once
 * ---------------------------------------------------------------------------
 *
 * Sanctum stores a hash. The plain token exists for exactly one response and
 * is then unrecoverable - which is the correct behaviour and the thing every
 * screen like this has to say out loud, because somebody who closes the
 * dialog without copying it will otherwise spend twenty minutes looking for
 * a "show token" button that must not exist.
 *
 * ---------------------------------------------------------------------------
 * A token is minted for a person
 * ---------------------------------------------------------------------------
 *
 * Not for "the system". It belongs to whoever created it, inherits their
 * branch through CurrentShop, and appears under their name in the order
 * trail - so an aggregator's actions are attributable to the person who
 * connected it rather than to nobody. And it is never more than that person:
 * only abilities whose right they hold can be minted, and every call checks
 * again (EnsureTokenOwnerMayAct).
 */
class ApiTokenController extends Controller
{
    /**
     * The abilities a token may be given.
     *
     * Deliberately a short, closed list. An ability that is not here cannot
     * be granted, so a typo in a form cannot mint something with powers no
     * route expects. The right behind each one is in ApiAbilities.
     *
     * @var array<string, string>
     */
    public const ABILITIES = ApiAbilities::LABELS;

    /**
     * How long a token lasts when nobody says. A year, not forever: a token
     * pasted into an aggregator's dashboard and forgotten should not outlive
     * the contract it was made for by a decade.
     */
    public const DEFAULT_LIFETIME_DAYS = 365;

    public function index(Request $request): View
    {
        $tokens = PersonalAccessToken::query()
            ->where('tokenable_type', $request->user()->getMorphClass())
            ->where('tokenable_id', $request->user()->id)
            ->latest('id')
            ->get();

        return view('admin.api-tokens.index', [
            'tokens' => $tokens,
            'abilities' => $this->grantable($request),
            'defaultLifetime' => self::DEFAULT_LIFETIME_DAYS,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $grantable = array_keys($this->grantable($request));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'in:'.implode(',', array_keys(self::ABILITIES))],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        /*
         | Nobody hands out a right they do not hold. Checked separately from
         | the closed list above so the message says what is actually wrong.
         */
        $beyond = array_values(array_diff($data['abilities'], $grantable));

        if ($beyond !== []) {
            $message = 'You cannot give a token abilities you do not have yourself: '.implode(', ', $beyond).'.';

            return ApiResponse::error($message, ['abilities' => [$message]], 422);
        }

        $token = $request->user()->createToken(
            $data['name'],
            $data['abilities'],
            now()->addDays((int) ($data['expires_in_days'] ?? self::DEFAULT_LIFETIME_DAYS)),
        );

        ActivityLog::record(
            'api_token.created',
            sprintf('Created API token "%s" with %s', $data['name'], implode(', ', $data['abilities'])),
            $request->user(),
        );

        return ApiResponse::success(
            'Token created. Copy it now — it cannot be shown again.',
            [
                // The one and only time this value exists in a response.
                'token' => $token->plainTextToken,
                'name' => $data['name'],
            ],
        );
    }

    public function destroy(Request $request, int $token): JsonResponse
    {
        $row = PersonalAccessToken::query()
            ->where('tokenable_type', $request->user()->getMorphClass())
            ->where('tokenable_id', $request->user()->id)
            ->whereKey($token)
            ->first();

        if ($row === null) {
            // Scoped to the caller's own tokens, so somebody cannot revoke a
            // colleague's integration by guessing an id.
            return ApiResponse::error('No such token.', [], 404);
        }

        $name = $row->name;
        $row->delete();

        ActivityLog::record('api_token.revoked', "Revoked API token \"{$name}\"", $request->user());

        return ApiResponse::success("\"{$name}\" revoked. Anything using it stops working immediately.");
    }

    /**
     * The abilities this person may put on a token: the ones whose right they hold.
     *
     * @return array<string, string>
     */
    private function grantable(Request $request): array
    {
        $user = $request->user();

        return array_filter(
            self::ABILITIES,
            fn (string $ability) => $user->can(ApiAbilities::permissionFor($ability)),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
