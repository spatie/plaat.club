<?php

declare(strict_types=1);

namespace Laravel\Converse\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Laravel\Converse\Attachment;
use Laravel\Converse\Attachments\StagingDirectory;
use Laravel\Converse\ConverseManager;
use Laravel\Converse\Enums\ThreadType;
use Laravel\Converse\Storage\PausedApproval;
use Laravel\Converse\Thread as ConverseThread;

/**
 * @property int $id
 * @property string $bot
 * @property string $connection
 * @property string $external_id
 * @property string $channel
 * @property ThreadType $type
 * @property string|null $agent_conversation_id
 * @property Collection<string, PausedApproval>|null $pending_approvals
 * @property int $last_event_sequence
 * @property CarbonImmutable|null $subscribed_at
 * @property array<string, mixed>|null $meta
 */
class Thread extends Model
{
    use Prunable;

    protected $table = 'converse_threads';

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
            'type' => ThreadType::class,
            'meta' => 'array',
            'pending_approvals' => AsCollection::of([PausedApproval::class, 'fromStoredArray']),
            'last_event_sequence' => 'integer',
            'subscribed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ConverseManager::messageModel(), 'thread_id');
    }

    /**
     * @return HasMany<Run, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(ConverseManager::runModel(), 'thread_id');
    }

    /**
     * @return HasMany<ThreadEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ConverseManager::threadEventModel(), 'thread_id')->orderBy('sequence');
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $cutoff = static::retentionCutoff();

        return static::query()
            ->whereNull('subscribed_at')
            ->where('created_at', '<=', $cutoff)
            ->whereDoesntHave('messages', fn (Builder $query): Builder => $query->where('created_at', '>', $cutoff))
            ->whereDoesntHave('runs', fn (Builder $query): Builder => $query->where('created_at', '>', $cutoff));
    }

    public static function retentionCutoff(): CarbonInterface
    {
        return Date::now()->subDays(max(1, (int) config('converse.storage.retention')));
    }

    protected function pruning(): void
    {
        rescue(fn (): bool => Storage::disk(Attachment::defaultDisk())
            ->deleteDirectory(StagingDirectory::forThread($this->toThread())));
    }

    public function toThread(): ConverseThread
    {
        return ConverseThread::fromExternalId((string) $this->getAttribute('connection'), $this->external_id, directMessage: $this->isDirectMessage(), bot: $this->bot ?: null);
    }

    public function isDirectMessage(): bool
    {
        return $this->type === ThreadType::DirectMessage;
    }
}
