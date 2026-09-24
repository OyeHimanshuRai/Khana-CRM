<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
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
 * connected it rather than to nobody.
 */
class ApiTokenController extends Controller
{
    /**
     * The abilities a token may be given.
     *
     * Deliberately a short, closed list. An ability that is not here cannot
     * be granted, so a typo in a form cannot mint something with powers no
     * route expects.
     *
     * @var array<string, string>
     */
    public const ABILITIES = [
        'menu:read' => 'Read the menu',
        'menu:write' => 'Mark dishes sold out',
        'tables:read' => 'Read the floor and its tables',
        'orders:read' => 'Read orders and their status',
        'orders:write' => 'Create orders and move them along',
    ];

    public function index(Request $request): View
    {
        $tokens = PersonalAccessToken::query()
            ->where('tokenable_type', $request->user()->getMorphClass())
            ->where('tokenable_id', $request->user()->id)
            ->latest('id')
            ->get();

        return view('admin.api-tokens.index', [
            'tokens' => $tokens,
            'abilities' => self::ABILITIES,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['string', 'in:'.implode(',', array_keys(self::ABILITIES))],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $token = $request->user()->createToken(
            $data['name'],
            $data['abilities'],
            filled($data['expires_in_days'] ?? null)
                ? now()->addDays((int) $data['expires_in_days'])
                : null,
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
}
