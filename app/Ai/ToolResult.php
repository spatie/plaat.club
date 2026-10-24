<?php

namespace App\Ai;

class ToolResult
{
    /** @param array<string, mixed> $data */
    public static function encode(array $data): string
    {
        array_walk_recursive($data, function (&$value): void {
            if (is_string($value)) {
                $value = mb_substr($value, 0, 500);
            }
        });

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
