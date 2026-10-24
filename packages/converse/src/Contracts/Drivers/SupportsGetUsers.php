<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts\Drivers;

use Laravel\Converse\User;

interface SupportsGetUsers extends Driver
{
    public function getUser(string $id): ?User;
}
