<?php

declare(strict_types=1);

namespace Laravel\Converse\Attachments;

use Illuminate\Support\Str;

class UploadPolicy
{
    /**
     * @param  array<int, string>  $mimeTypes
     */
    public function __construct(
        protected int $maxBytes,
        protected int $maxFiles,
        protected array $mimeTypes,
    ) {}

    public static function fromConfig(): static
    {
        return new static(
            maxBytes: max(0, (int) config('converse.attachments.max_bytes')),
            maxFiles: max(0, (int) config('converse.attachments.max_files')),
            mimeTypes: array_values(array_filter(
                (array) config('converse.attachments.mime_types', []),
                is_string(...),
            )),
        );
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    public function maxFiles(): int
    {
        return $this->maxFiles;
    }

    public function isDisabled(): bool
    {
        return $this->maxBytes === 0 || $this->maxFiles === 0 || $this->mimeTypes === [];
    }

    public function allows(?string $mimeType): bool
    {
        if ($this->isDisabled() || $mimeType === null || $mimeType === '') {
            return false;
        }

        return Str::is($this->mimeTypes, Str::before($mimeType, ';'), ignoreCase: true);
    }

    public function permits(int $bytes): bool
    {
        return $bytes <= $this->maxBytes;
    }
}
