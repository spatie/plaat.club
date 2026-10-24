<?php

declare(strict_types=1);

namespace Laravel\Converse;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
class User implements Arrayable, JsonSerializable
{
    public function __construct(
        protected string $id,
        protected ?string $name = null,
        protected ?string $username = null,
        protected bool $isBot = false,
        protected bool $isSelf = false,
    ) {}

    public static function anonymous(): static
    {
        return new static('');
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function username(): ?string
    {
        return $this->username;
    }

    public function isBot(): bool
    {
        return $this->isBot;
    }

    public function isSelf(): bool
    {
        return $this->isSelf;
    }

    public function isAnonymous(): bool
    {
        return $this->id === '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'is_bot' => $this->isBot,
            'is_self' => $this->isSelf,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromStoredArray(array $data): static
    {
        return new static(
            id: (string) Arr::get($data, 'id', ''),
            name: Arr::get($data, 'name'),
            username: Arr::get($data, 'username'),
            isBot: (bool) Arr::get($data, 'is_bot', false),
            isSelf: (bool) Arr::get($data, 'is_self', false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
