<?php

declare(strict_types=1);

namespace Laravel\Converse;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Stringable;

/** @implements Arrayable<string, mixed> */
class Channel implements Arrayable, JsonSerializable, Stringable
{
    /**
     * @param  (Closure(): ?Channel)|null  $resolver
     */
    public function __construct(
        protected string $id,
        protected ?string $name = null,
        protected ?bool $isDirectMessage = null,
        protected ?int $memberCount = null,
        protected ?Closure $resolver = null,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function name(): ?string
    {
        return $this->name ?? $this->resolve()->name;
    }

    public function isDirectMessage(): ?bool
    {
        return $this->isDirectMessage ?? $this->resolve()->isDirectMessage;
    }

    public function memberCount(): ?int
    {
        return $this->memberCount ?? $this->resolve()->memberCount;
    }

    public function __toString(): string
    {
        return $this->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'id' => $this->id,
            'name' => $this->name,
            'is_direct_message' => $this->isDirectMessage,
            'member_count' => $this->memberCount,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    protected function resolve(): static
    {
        return once(function (): static {
            $channel = $this->resolver instanceof Closure ? ($this->resolver)() : null;

            return $channel instanceof static
                ? new static($this->id, $this->name ?? $channel->name, $this->isDirectMessage ?? $channel->isDirectMessage, $this->memberCount ?? $channel->memberCount)
                : $this;
        });
    }
}
