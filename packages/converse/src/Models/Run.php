<?php

declare(strict_types=1);

namespace Laravel\Converse\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Date;
use Laravel\Converse\ConverseManager;
use Laravel\Converse\Enums\RunStatus;

/**
 * @property string $id
 * @property int $thread_id
 * @property string $thread_lock
 * @property string|null $prompt_message_id
 * @property string|null $client_message_id
 * @property RunStatus $status
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Thread $thread
 */
class Run extends Model
{
    protected $table = 'converse_runs';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var array<int, string> */
    protected $guarded = [];

    public function getConnectionName(): ?string
    {
        return config('converse.storage.connection') ?? parent::getConnectionName();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'finished_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(ConverseManager::threadModel(), 'thread_id');
    }

    /**
     * @return HasMany<ThreadEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ConverseManager::threadEventModel(), 'run_id')->orderBy('sequence');
    }

    public static function threadLockFor(int $threadId): string
    {
        return "thread:{$threadId}";
    }

    public static function releasedLockFor(string $runId): string
    {
        return "released:{$runId}";
    }

    public function isStale(): bool
    {
        return ! $this->status->isFinished() && $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->whereIn('status', [RunStatus::Queued, RunStatus::Running]);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function stale(Builder $query): void
    {
        $query->staleAt(Date::now());
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function staleAt(Builder $query, CarbonInterface $moment): void
    {
        $query->live()->where('expires_at', '<=', $moment);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function endedBefore(Builder $query, CarbonInterface $cutoff): void
    {
        $query->where(fn (Builder $query): Builder => $query
            ->where('finished_at', '<=', $cutoff)
            ->orWhere(fn (Builder $query): Builder => $query->staleAt($cutoff)));
    }
}
