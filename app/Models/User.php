<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $handle
 * @property string $email
 * @property string $password
 * @property ?string $google_id
 * @property ?string $google_avatar_url
 * @property bool $legacy_owner
 * @property string $visibility
 * @property bool $comments_enabled
 * @property bool $digest_enabled
 * @property ?string $digest_week
 * @property ?string $discogs_username
 * @property ?string $discogs_token
 * @property ?string $discogs_secret
 * @property ?CarbonImmutable $email_verified_at
 * @property ?CarbonImmutable $collection_imported_at
 * @property ?CarbonImmutable $last_synced_at
 * @property ?CarbonImmutable $discogs_additions_checked_at
 * @property ?string $sync_status
 * @property ?int $sync_total
 * @property ?int $ai_daily_question_limit
 * @property ?array{page: int, seen: list<int>, total: ?int, pages: ?int, startedAt: int, full?: bool} $discogs_sync_state
 */
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    use HasPublicId;
    use Notifiable;

    public const RESERVED_HANDLES = [
        'about', 'admin', 'api', 'ask', 'auth', 'build', 'collection', 'collections', 'community', 'demo', 'discogs',
        'email', 'favicon', 'feed', 'fonts', 'friends', 'images', 'index', 'lists', 'login', 'logout',
        'notifications', 'people', 'privacy', 'record', 'register', 'robots', 'settings', 'share',
        'shelves', 'storage', 'terms', 'up',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'email', 'google_id', 'google_avatar_url', 'discogs_token', 'discogs_secret', 'discogs_sync_state'];

    protected static function booted(): void
    {
        static::creating(function (self $user): void {
            $localPart = Str::before($user->email, '@');
            if (blank($user->name)) {
                $user->name = (string) Str::of($localPart)->replaceMatches('/[^\pL\pN]+/u', ' ')->headline()->trim()->substr(0, 80);
                $user->name = $user->name ?: 'Collector';
            }

            if (filled($user->handle)) {
                return;
            }

            $base = (string) Str::of($localPart)->ascii()->lower()->replaceMatches('/[^a-z0-9_]+/', '_')->trim('_')->substr(0, 30);
            $base = strlen($base) < 3 ? "collector_{$base}" : $base;
            $user->handle = $base;

            while (in_array($user->handle, self::RESERVED_HANDLES, true) || self::query()->where('handle', $user->handle)->exists()) {
                $user->handle = Str::substr($base, 0, 21).'_'.Str::lower(Str::random(8));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed', 'email_verified_at' => 'immutable_datetime',
            'legacy_owner' => 'boolean', 'comments_enabled' => 'boolean', 'digest_enabled' => 'boolean',
            'discogs_token' => 'encrypted', 'discogs_secret' => 'encrypted',
            'collection_imported_at' => 'immutable_datetime', 'last_synced_at' => 'immutable_datetime',
            'discogs_additions_checked_at' => 'immutable_datetime',
            'sync_total' => 'integer',
            'ai_daily_question_limit' => 'integer',
            'discogs_sync_state' => 'array',
        ];
    }

    public static function freek(): self
    {
        return static::query()->where('legacy_owner', true)->firstOrFail();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->email === 'freek@spatie.be' && $this->hasVerifiedEmail();
    }

    /** @return HasMany<CollectionRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(CollectionRecord::class)->chaperone();
    }

    /** @return Builder<User> */
    public function friends(): Builder
    {
        return self::query()->where(function (Builder $query): void {
            $query->whereIn('id', Friendship::query()
                ->whereNotNull('accepted_at')->where('user_low', $this->id)->select('user_high'))
                ->orWhereIn('id', Friendship::query()
                    ->whereNotNull('accepted_at')->where('user_high', $this->id)->select('user_low'));
        });
    }

    /** @return list<string> */
    public function allowedCollectionVisibilities(): array
    {
        return $this->legacy_owner ? ['everyone'] : ['only_me', 'friends', 'everyone'];
    }

    public function canViewCollection(?self $viewer): bool
    {
        return $this->visibility === 'everyone' || $viewer?->id === $this->id
            || ($this->visibility === 'friends' && $viewer !== null && $this->friends()->whereKey($viewer->id)->exists());
    }
}
