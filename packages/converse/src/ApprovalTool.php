<?php

declare(strict_types=1);

namespace Laravel\Converse;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
class ApprovalTool implements Arrayable, JsonSerializable
{
    /**
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        protected string $id,
        protected string $name,
        protected array $arguments = [],
        protected ?string $reason = null,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'arguments' => $this->arguments,
            'reason' => $this->reason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
