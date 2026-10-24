<?php

declare(strict_types=1);

namespace Laravel\Converse\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Laravel\Converse\Attachment;
use Laravel\Converse\BotEvents\Message as MessageEvent;
use Laravel\Converse\Casts\AsAttachment;
use Laravel\Converse\Casts\UtcMicrosecondTimestamp;
use Laravel\Converse\ConverseManager;
use Laravel\Converse\Enums\MessageRole;
use Laravel\Converse\Enums\MessageStatus;
use Laravel\Converse\Enums\MessageType;
use Laravel\Converse\Exceptions\ShouldNotHappenException;
use Laravel\Converse\User;

/**
 * @property int $id
 * @property int $thread_id
 * @property MessageRole $role
 * @property MessageType $type
 * @property string|null $external_id
 * @property array<string, mixed>|null $external_user
 * @property string $text
 * @property Collection<int, Attachment> $attachments
 * @property CarbonImmutable $external_sent_at
 * @property CarbonImmutable|null $external_edited_at
 * @property MessageStatus $status
 * @property-read Thread $thread
 */
class Message extends Model
{
    use SoftDeletes;

    protected $table = 'converse_messages';

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
            'external_user' => 'array',
            'attachments' => AsAttachment::class,
            'type' => MessageType::class,
            'external_sent_at' => UtcMicrosecondTimestamp::class,
            'external_edited_at' => UtcMicrosecondTimestamp::class,
            'role' => MessageRole::class,
            'status' => MessageStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(ConverseManager::threadModel(), 'thread_id');
    }

    public function toEvent(): MessageEvent
    {
        $thread = $this->relationLoaded('thread')
            ? $this->getRelation('thread')
            : $this->thread()->first();

        if (! $thread instanceof Thread) {
            throw new ShouldNotHappenException("Message [{$this->getKey()}] has no thread [{$this->thread_id}].");
        }

        $message = MessageEvent::make(
            thread: $thread->toThread(),
            text: $this->text,
            user: is_array($this->external_user) ? User::fromStoredArray($this->external_user) : User::anonymous(),
            sentAt: $this->external_sent_at,
        )->withMention($this->type === MessageType::Mention)
            ->withDirectMessage($thread->isDirectMessage())
            ->withScheduled($this->type === MessageType::Scheduled)
            ->withId($this->external_id)
            ->withEditedAt($this->external_edited_at);

        [$staged, $unavailableAttachments] = $this->attachments
            ->partition(fn (Attachment $attachment): bool => $attachment->unavailable() === null)
            ->all();

        return $message
            ->withAttachments($staged, $unavailableAttachments)
            ->withStorageId((int) $this->getKey());
    }
}
