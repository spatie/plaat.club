<?php

declare(strict_types=1);

namespace Laravel\Converse\Testing;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Converse\Approval;
use Laravel\Converse\ApprovalTool;
use Laravel\Converse\Attachment;
use Laravel\Converse\Cards\Card;
use Laravel\Converse\Channel;
use Laravel\Converse\Contracts\Drivers\ReceivesWebhooks;
use Laravel\Converse\Contracts\Drivers\SupportsAddReactions;
use Laravel\Converse\Contracts\Drivers\SupportsDeleteMessages;
use Laravel\Converse\Contracts\Drivers\SupportsDownloadAttachments;
use Laravel\Converse\Contracts\Drivers\SupportsEditMessages;
use Laravel\Converse\Contracts\Drivers\SupportsFindChannels;
use Laravel\Converse\Contracts\Drivers\SupportsFindUsers;
use Laravel\Converse\Contracts\Drivers\SupportsGetUsers;
use Laravel\Converse\Contracts\Drivers\SupportsOpenDirectMessages;
use Laravel\Converse\Contracts\Drivers\SupportsRemoveReactions;
use Laravel\Converse\Contracts\Drivers\SupportsSendAttachments;
use Laravel\Converse\Contracts\Drivers\SupportsSendEphemeralMessages;
use Laravel\Converse\Contracts\Drivers\SupportsUpdateStatuses;
use Laravel\Converse\Drivers\Concerns\RendersApprovalCards;
use Laravel\Converse\Enums\Emoji;
use Laravel\Converse\Enums\ThreadStatus;
use Laravel\Converse\Exceptions\AttachmentTooLargeException;
use Laravel\Converse\Markdown;
use Laravel\Converse\SentMessage;
use Laravel\Converse\Thread;
use Laravel\Converse\User;
use PHPUnit\Framework\Assert;

class ConverseFake implements ReceivesWebhooks, SupportsAddReactions, SupportsDeleteMessages, SupportsDownloadAttachments, SupportsEditMessages, SupportsFindChannels, SupportsFindUsers, SupportsGetUsers, SupportsOpenDirectMessages, SupportsRemoveReactions, SupportsSendAttachments, SupportsSendEphemeralMessages, SupportsUpdateStatuses
{
    use RendersApprovalCards {
        requestApproval as renderApprovalCard;
        resolveApproval as renderDecisionCard;
    }

    /** @var Collection<string, User> */
    protected Collection $userInfo;

    /** @var array<string, string> */
    protected array $channels = [];

    /**
     * @var Collection<int, array{thread: Thread, content: Card|Markdown|string}>
     */
    protected Collection $sent;

    /**
     * @var Collection<int, array{thread: Thread, message_id: string, content: Card|Markdown|string}>
     */
    protected Collection $edited;

    /** @var Collection<int, array{thread: Thread, message_id: string}> */
    protected Collection $deleted;

    /**
     * @var Collection<int, array{thread: Thread, attachment: Attachment, content: Card|Markdown|string}>
     */
    protected Collection $attachments;

    /** @var array<string, string> */
    protected array $downloads = [];

    /**
     * @var Collection<int, array{thread: Thread, message_id: string, emoji: string}>
     */
    protected Collection $reactions;

    /**
     * @var Collection<int, array{thread: Thread, message_id: string, emoji: string}>
     */
    protected Collection $removedReactions;

    /**
     * @var Collection<int, array{thread: Thread, status: ThreadStatus|string|null}>
     */
    protected Collection $statuses;

    /** @var Collection<int, array{thread: Thread, approval: Approval}> */
    protected Collection $approvals;

    /** @var Collection<int, array{thread: Thread, message_id: string, decisions: array<string, bool>}> */
    protected Collection $decisions;

    /**
     * @var array<string, string>
     */
    protected array $directMessageRecipients = [];

    protected int $nextId = 0;

    public function __construct()
    {
        $this->userInfo = collect();
        $this->sent = collect();
        $this->edited = collect();
        $this->deleted = collect();
        $this->attachments = collect();
        $this->reactions = collect();
        $this->removedReactions = collect();
        $this->statuses = collect();
        $this->approvals = collect();
        $this->decisions = collect();
    }

    public function connection(): string
    {
        return 'fake';
    }

    public function platform(): string
    {
        return 'fake';
    }

    public function verify(Request $request): bool
    {
        return true;
    }

    public function withUsers(User ...$users): static
    {
        foreach ($users as $user) {
            $this->userInfo->put($user->id(), $user);
        }

        return $this;
    }

    public function getUser(string $id): ?User
    {
        return $this->userInfo->get($id);
    }

    public function withChannels(Channel ...$channels): static
    {
        foreach ($channels as $channel) {
            $this->channels[(string) $channel->name()] = $channel->id();
        }

        return $this;
    }

    public function findChannel(string $name): ?Channel
    {
        $id = $this->channels[$name] ?? null;

        return $id === null ? null : new Channel($id, $name);
    }

    public function findUser(string $username): ?User
    {
        $username = ltrim($username, '@');

        return $this->userInfo->first(fn (User $user): bool => $user->username() === $username);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return iterable<int, never>
     */
    public function events(array $payload): iterable
    {
        return [];
    }

    public function sendMessage(Thread $thread, Card|Markdown|string $content): SentMessage
    {
        $this->sent->push(['thread' => $thread, 'content' => $content]);

        return new SentMessage($thread, 'fake-'.++$this->nextId);
    }

    public function withAttachmentContents(string $id, string $contents): static
    {
        $this->downloads[$id] = $contents;

        return $this;
    }

    public function downloadAttachment(Attachment $attachment, int $maxBytes, int $timeout): string
    {
        $contents = $this->downloads[(string) $attachment->id()] ?? 'fake-attachment-contents';

        if (strlen($contents) > $maxBytes) {
            throw new AttachmentTooLargeException;
        }

        return $contents;
    }

    public function sendAttachment(Thread $thread, Attachment $attachment, Card|Markdown|string|null $caption = null): SentMessage
    {
        $this->attachments->push([
            'thread' => $thread,
            'attachment' => $attachment,
            'content' => $caption ?? $attachment->label(),
        ]);

        return new SentMessage($thread, 'fake-'.++$this->nextId);
    }

    public function openDirectMessage(string $recipient): Thread
    {
        $thread = new Thread($this->connection(), 'direct-'.hash('sha256', $recipient), directMessage: true);

        $this->directMessageRecipients[$thread->channel()->id()] = $recipient;

        return $thread;
    }

    public function sendEphemeralMessage(Thread $thread, Card|Markdown|string $content, User $to): SentMessage
    {
        return $this->sendMessage($thread, $content);
    }

    public function editMessage(Thread $thread, string $messageId, Card|Markdown|string $content): SentMessage
    {
        $this->edited->push(['thread' => $thread, 'message_id' => $messageId, 'content' => $content]);

        return new SentMessage($thread, $messageId);
    }

    public function deleteMessage(Thread $thread, string $messageId): void
    {
        $this->deleted->push(['thread' => $thread, 'message_id' => $messageId]);
    }

    public function addReaction(Thread $thread, string $messageId, Emoji|string $emoji): void
    {
        $this->reactions->push(['thread' => $thread, 'message_id' => $messageId, 'emoji' => Emoji::unicode($emoji)]);
    }

    public function removeReaction(Thread $thread, string $messageId, Emoji|string $emoji): void
    {
        $this->removedReactions->push(['thread' => $thread, 'message_id' => $messageId, 'emoji' => Emoji::unicode($emoji)]);
    }

    public function updateStatus(Thread $thread, ThreadStatus|string|null $status = null): void
    {
        $this->statuses->push(['thread' => $thread, 'status' => $status]);
    }

    public function requestApproval(Thread $thread, Approval $approval): SentMessage
    {
        $this->approvals->push(['thread' => $thread, 'approval' => $approval]);

        return $this->renderApprovalCard($thread, $approval);
    }

    /**
     * @param  array<string, bool>  $decisions
     */
    public function resolveApproval(Thread $thread, SentMessage $request, array $decisions): SentMessage
    {
        $this->decisions->push(['thread' => $thread, 'message_id' => $request->id(), 'decisions' => $decisions]);

        return $this->renderDecisionCard($thread, $request, $decisions);
    }

    public function mention(User|string $user): Markdown
    {
        return Markdown::make('<@'.($user instanceof User ? $user->id() : $user).'>');
    }

    /**
     * @return Collection<int, array{thread: Thread, content: Card|Markdown|string}>
     */
    public function sent(): Collection
    {
        return $this->sent;
    }

    /**
     * @return Collection<int, array{thread: Thread, message_id: string, content: Card|Markdown|string}>
     */
    public function edited(): Collection
    {
        return $this->edited;
    }

    /** @return Collection<int, array{thread: Thread, message_id: string}> */
    public function deleted(): Collection
    {
        return $this->deleted;
    }

    /**
     * @return Collection<int, array{thread: Thread, attachment: Attachment, content: Card|Markdown|string}>
     */
    public function attachments(): Collection
    {
        return $this->attachments;
    }

    /**
     * @return Collection<int, array{thread: Thread, message_id: string, emoji: string}>
     */
    public function addedReactions(): Collection
    {
        return $this->reactions;
    }

    /**
     * @return Collection<int, array{thread: Thread, message_id: string, emoji: string}>
     */
    public function removedReactions(): Collection
    {
        return $this->removedReactions;
    }

    /**
     * @return Collection<int, array{thread: Thread, status: ThreadStatus|string|null}>
     */
    public function statuses(): Collection
    {
        return $this->statuses;
    }

    /** @return Collection<int, array{thread: Thread, approval: Approval}> */
    public function approvals(): Collection
    {
        return $this->approvals;
    }

    /** @return Collection<int, array{thread: Thread, message_id: string, decisions: array<string, bool>}> */
    public function decisions(): Collection
    {
        return $this->decisions;
    }

    /**
     * @param  Closure(Card|Markdown|string, Thread): bool|string|null  $expected
     */
    public function assertSent(Closure|string|null $expected = null): void
    {
        Assert::assertTrue(
            $this->matches($this->sent, 'content', $expected),
            'An expected message was not sent.',
        );
    }

    /**
     * @param  Closure(Card|Markdown|string, Thread): bool|string|null  $expected
     */
    public function assertAttachmentSent(Closure|string|null $expected = null): void
    {
        Assert::assertTrue(
            $this->matches($this->attachments, 'content', $expected),
            'An expected attachment was not sent.',
        );
    }

    /**
     * @param  Closure(Card|Markdown|string, Thread): bool|string|null  $expected
     */
    public function assertAttachmentNotSent(Closure|string|null $expected = null): void
    {
        Assert::assertFalse(
            $this->matches($this->attachments, 'content', $expected),
            'An unexpected attachment was sent.',
        );
    }

    /**
     * @param  Closure(Card|Markdown|string, Thread): bool|string|null  $expected
     */
    public function assertNotSent(Closure|string|null $expected = null): void
    {
        Assert::assertFalse(
            $this->matches($this->sent, 'content', $expected),
            'An unexpected message was sent.',
        );
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->sent->all(), 'Messages were unexpectedly sent.');
    }

    public function assertSentCount(int $count): void
    {
        Assert::assertCount($count, $this->sent, "Expected [{$count}] messages to be sent, but [{$this->sent->count()}] were sent.");
    }

    /**
     * @param  Closure(Card|Markdown|string, Thread): bool|string|null  $expected
     */
    public function assertEdited(Closure|string|null $expected = null): void
    {
        Assert::assertTrue(
            $this->matches($this->edited, 'content', $expected),
            'An expected message was not edited.',
        );
    }

    /**
     * @param  Closure(Card|Markdown|string, Thread): bool|string|null  $expected
     */
    public function assertNotEdited(Closure|string|null $expected = null): void
    {
        Assert::assertFalse(
            $this->matches($this->edited, 'content', $expected),
            'An unexpected message was edited.',
        );
    }

    public function assertDeleted(?string $messageId = null): void
    {
        Assert::assertTrue($this->wasDeleted($messageId), 'An expected message was not deleted.');
    }

    public function assertNotDeleted(?string $messageId = null): void
    {
        Assert::assertFalse($this->wasDeleted($messageId), 'An unexpected message was deleted.');
    }

    public function assertReactionAdded(Emoji|string|null $emoji = null): void
    {
        Assert::assertTrue(
            $this->reactions->contains(fn (array $record): bool => $emoji === null || $record['emoji'] === Emoji::unicode($emoji)),
            'An expected reaction was not added.',
        );
    }

    public function assertReactionNotAdded(Emoji|string|null $emoji = null): void
    {
        Assert::assertFalse(
            $this->reactions->contains(fn (array $record): bool => $emoji === null || $record['emoji'] === Emoji::unicode($emoji)),
            'An unexpected reaction was added.',
        );
    }

    public function assertReactionRemoved(Emoji|string|null $emoji = null): void
    {
        Assert::assertTrue(
            $this->removedReactions->contains(fn (array $record): bool => $emoji === null || $record['emoji'] === Emoji::unicode($emoji)),
            'An expected reaction was not removed.',
        );
    }

    public function assertStatus(ThreadStatus|string $status): void
    {
        Assert::assertTrue(
            $this->statuses->contains(fn (array $record): bool => $this->statusText($record['status']) === $this->statusText($status)),
            'An expected status was not shown.',
        );
    }

    public function assertApprovalRequested(?string $tool = null): void
    {
        Assert::assertTrue(
            $this->approvals->contains(fn (array $record): bool => $tool === null || $record['approval']->tools()->contains(fn (ApprovalTool $approvalTool): bool => $approvalTool->name() === $tool)),
            'An expected approval was not requested.',
        );
    }

    public function assertNoApprovalRequested(): void
    {
        Assert::assertTrue($this->approvals->isEmpty(), 'An approval was unexpectedly requested.');
    }

    public function assertApprovalResolved(?bool $approved = null): void
    {
        Assert::assertTrue(
            $this->decisions->contains(fn (array $record): bool => $approved === null || Approval::wasApproved($record['decisions']) === $approved),
            'An expected approval was not resolved.',
        );
    }

    /**
     * @param  Closure(Card|Markdown|string, Thread): bool|string|null  $expected
     */
    public function assertDirectMessageSent(string $connection, User|string $recipient, Closure|string|null $expected = null): void
    {
        $recipient = $recipient instanceof User ? $recipient->id() : $recipient;
        $records = $this->sent->filter(
            fn (array $record): bool => $record['thread']->connection() === $connection
                && ($this->directMessageRecipients[$record['thread']->channel()->id()] ?? null) === $recipient,
        );

        Assert::assertTrue(
            $this->matches($records, 'content', $expected),
            "An expected direct message was not sent to [{$recipient}] on [{$connection}].",
        );
    }

    protected function statusText(ThreadStatus|string|null $status): string
    {
        return $status instanceof ThreadStatus ? $status->value : (string) $status;
    }

    protected function wasDeleted(?string $messageId): bool
    {
        return $this->deleted->contains(fn (array $record): bool => $messageId === null || $record['message_id'] === $messageId);
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $records
     */
    protected function matches(iterable $records, string $field, Closure|string|null $expected): bool
    {
        return collect($records)->contains(function (array $record) use ($field, $expected): bool {
            $value = $record[$field];

            return match (true) {
                $expected === null => true,
                $expected instanceof Closure => $expected($value, $record['thread']),
                default => Str::contains($value instanceof Card ? $value->fallbackText() : (string) $value, $expected),
            };
        });
    }
}
