<?php

declare(strict_types=1);

namespace Laravel\Converse\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Converse\ConverseManager;

/**
 * @property int $id
 * @property int $thread_id
 * @property string $run_id
 * @property int $sequence
 * @property array<mixed> $payload
 * @property CarbonImmutable|null $created_at
 * @property-read Thread $thread
 * @property-read Run $run
 */
class ThreadEvent extends Model
{
    use MassPrunable;

    public const null UPDATED_AT = null;

    protected $table = 'converse_thread_events';

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
            'sequence' => 'integer',
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
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
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ConverseManager::runModel(), 'run_id');
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $cutoff = ConverseManager::threadModel()::retentionCutoff();

        return static::query()->whereHas('run', fn (Builder $query): Builder => $query->endedBefore($cutoff));
    }
}
