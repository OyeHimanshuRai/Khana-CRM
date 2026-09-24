<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\CurrentTenant;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /** Role that bypasses every permission check. */
    public const SUPER_ADMIN = 'Super Admin';

    /**
     * Role that runs one business and nothing else.
     *
     * Named here because it is no longer only a seeder's string: a self-serve
     * signup hands it to whoever created the account, and a typo in that one
     * place would produce an owner locked out of their own back office. See
     * App\Services\SignupService and RolePermissionSeeder.
     */
    public const TENANT_OWNER = 'Tenant Owner';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'mobile',
        'password',
        'is_admin',
        'is_active',
        'current_shop_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
            'all_shops_view' => 'boolean',
            'last_login_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * A new account joins the business its creator is working in.
     *
     * Mirrors what BelongsToShop does for shop_id, and for the same reason:
     * the tenant is context, not something an admin should have to restate
     * on every form. Left alone when it is already set, and left null when
     * there is no tenant in context - which is the case when a Super Admin
     * is creating another Super Admin from the consolidated view, and null
     * is exactly right there.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if ($user->getAttribute('tenant_id') === null) {
                $user->setAttribute('tenant_id', CurrentTenant::id());
            }
        });
    }

    /* ------------------------------------------------------- relations */

    /**
     * The business this account belongs to.
     *
     * Null means Super Admin: an account that belongs to no single business
     * and oversees all of them. See App\Support\CurrentTenant.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** The tenant a Super Admin is currently looking at, or null for all. */
    public function currentTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'current_tenant_id');
    }

    /**
     * The shops this account is authorised to work in.
     *
     * A row on this pivot is the "explicit authorization" the tenant rules
     * turn on. Super Admin is the exception and bypasses it - see
     * App\Support\CurrentShop.
     */
    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class)
            ->withPivot('is_default')
            ->withTimestamps();
    }

    /** The shop currently selected in the switcher, or null for All shops. */
    public function currentShop(): BelongsTo
    {
        return $this->belongsTo(Shop::class, 'current_shop_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class)->latest('login_at');
    }

    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class)->latest();
    }

    public function blockedIps(): HasMany
    {
        return $this->hasMany(BlockedIp::class)->latest();
    }

    /* -------------------------------------------------- online status */

    /**
     * Online means "seen within the window", not "has a session row".
     *
     * A browser that was closed without signing out leaves its session
     * behind, so presence has to be measured by activity.
     */
    public function isOnline(): bool
    {
        return $this->last_seen_at?->gt(now()->subMinutes(UserSession::ONLINE_WINDOW)) === true;
    }

    /** "Online" or "Last seen 4 minutes ago". */
    public function presence(): string
    {
        if ($this->isOnline()) {
            return 'Online';
        }

        return $this->last_seen_at
            ? 'Last seen '.$this->last_seen_at->diffForHumans()
            : 'Never signed in';
    }

    /**
     * Sign every device out, optionally sparing the one making the request.
     *
     * @return int  how many sessions were closed
     */
    public function terminateSessions(?string $exceptSessionId = null): int
    {
        $sessions = $this->sessions()
            ->whereNull('logout_at')
            ->when($exceptSessionId, fn ($query) => $query->where('session_id', '!=', $exceptSessionId))
            ->get();

        foreach ($sessions as $session) {
            $session->terminate('admin');
        }

        return $sessions->count();
    }

    /**
     * Distinct addresses this account has ever been seen from, newest first.
     *
     * @return Collection<int, object>
     */
    public function ipHistory(): Collection
    {
        return LoginHistory::query()
            ->selectRaw('ip_address, COUNT(*) as attempts')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as successes', [LoginHistory::SUCCESS])
            ->selectRaw('MAX(created_at) as last_used_at')
            ->where('user_id', $this->id)
            ->whereNotNull('ip_address')
            ->groupBy('ip_address')
            ->orderByDesc('last_used_at')
            ->get();
    }

    /**
     * Super admins bypass permission checks (see AppServiceProvider's
     * Gate::before). Kept as a method so the rule lives in one place.
     */
    public function isSuperAdmin(): bool
    {
        return $this->hasRole(self::SUPER_ADMIN);
    }

    /**
     * Initials for avatar placeholders.
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part) => Str::substr($part, 0, 1))
            ->implode('');
    }

    /**
     * Public URL of the uploaded profile picture, or null to fall back to
     * initials(). Deliberately tolerant of a missing file: a row pointing at
     * something that has been swept off disk should degrade to the
     * placeholder rather than render a broken image.
     */
    public function avatarUrl(): ?string
    {
        if (blank($this->avatar_path)) {
            return null;
        }

        $disk = Storage::disk('public');

        return $disk->exists($this->avatar_path)
            ? $disk->url($this->avatar_path)
            : null;
    }
}
