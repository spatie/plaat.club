<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts;

interface RunEvents
{
    /**
     * @param  array<string, mixed>  $event
     */
    public function write(array $event): void;
}
