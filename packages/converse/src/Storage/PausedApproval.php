<?php

declare(strict_types=1);

namespace Laravel\Converse\Storage;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\InteractsWithTime;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
class PausedApproval implements Arrayable, JsonSerializable
{
    use InteractsWithTime;

    public function __construct(
        protected string $continuation,
        protected ?string $user,
        protected int $expiresAt,
        protected bool $awaitingReply = true,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromStoredArray(array $data): static
    {
        return new static(
            continuation: (string) Arr::get($data, 'continuation', ''),
            user: Arr::get($data, 'user'),
            expiresAt: (int) Arr::get($data, 'expires_at', 0),
            awaitingReply: (bool) Arr::get($data, 'awaiting_reply', true),
        );
    }

    public function continuation(): string
    {
        return $this->continuation;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt <= $this->currentTime();
    }

    public function canBeDecidedBy(string $user): bool
    {
        return $this->user === null || $this->user === $user;
    }

    public function isAwaitingReply(): bool
    {
        return $this->awaitingReply;
    }

    public function withoutAwaitingReply(): static
    {
        $approval = clone $this;
        $approval->awaitingReply = false;

        return $approval;
    }

    /**
     * @return array{continuation: string, user: string|null, expires_at: int, awaiting_reply: bool}
     */
    public function toArray(): array
    {
        return [
            'continuation' => $this->continuation,
            'user' => $this->user,
            'expires_at' => $this->expiresAt,
            'awaiting_reply' => $this->awaitingReply,
        ];
    }

    /**
     * @return array{continuation: string, user: string|null, expires_at: int, awaiting_reply: bool}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
