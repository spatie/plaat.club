<?php

declare(strict_types=1);

namespace Laravel\Converse\Concerns;

trait BuildsCacheKeys
{
    protected function cacheKey(string $prefix, ?string $bot, string ...$parts): string
    {
        return "laravel_converse:{$prefix}:".hash('sha256', implode(':', array_map(rawurlencode(...), [$bot ?? '', ...$parts])));
    }
}
