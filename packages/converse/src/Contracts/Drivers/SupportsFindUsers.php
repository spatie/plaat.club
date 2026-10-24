<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts\Drivers;

use Laravel\Converse\User;

interface SupportsFindUsers extends Driver
{
    public function findUser(string $username): ?User;
}
