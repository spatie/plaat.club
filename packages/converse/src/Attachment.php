<?php

declare(strict_types=1);

namespace Laravel\Converse;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonSerializable;
use Laravel\Converse\Enums\AttachmentType;
use Laravel\Converse\Exceptions\AttachmentException;

/** @implements Arrayable<string, mixed> */
class Attachment implements Arrayable, JsonSerializable
{
    public const string DEFAULT_MIME_TYPE = 'application/octet-stream';

    public function __construct(
        protected AttachmentType $type,
        protected string $mimeType = self::DEFAULT_MIME_TYPE,
        protected ?string $name = null,
        protected ?int $size = null,
        protected ?string $id = null,
        protected ?string $url = null,
        protected ?string $disk = null,
        protected ?string $path = null,
        protected ?string $contents = null,
        protected ?string $unavailable = null,
    ) {}

    public static function defaultDisk(): string
    {
        return (string) (config('converse.attachments.disk') ?? config('filesystems.default'));
    }

    public static function fromStorage(string $path, ?string $disk = null, ?string $name = null): static
    {
        $disk ??= static::defaultDisk();
        $mimeType = rescue(fn (): string => Storage::disk($disk)->mimeType($path) ?: static::DEFAULT_MIME_TYPE, static::DEFAULT_MIME_TYPE, false);

        return new static(
            type: AttachmentType::fromMimeType($mimeType),
            mimeType: $mimeType,
            name: $name ?? basename($path),
            size: rescue(fn (): int => Storage::disk($disk)->size($path), null, false),
            disk: $disk,
            path: $path,
        );
    }

    public static function fromPath(string $path, ?string $name = null): static
    {
        return static::fromContents(File::get($path), $name ?? basename($path), File::mimeType($path) ?: null);
    }

    public static function fromContents(string $contents, string $name, ?string $mimeType = null): static
    {
        $mimeType ??= static::DEFAULT_MIME_TYPE;

        return new static(
            type: AttachmentType::fromMimeType($mimeType),
            mimeType: $mimeType,
            name: $name,
            size: strlen($contents),
            contents: $contents,
        );
    }

    public static function fromUrl(string $url, ?string $name = null, ?string $mimeType = null): static
    {
        $mimeType ??= static::DEFAULT_MIME_TYPE;

        return new static(
            type: AttachmentType::fromMimeType($mimeType),
            mimeType: $mimeType,
            name: $name,
            url: $url,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromStoredArray(array $data): static
    {
        $disk = Arr::get($data, 'disk');
        $stored = is_string($disk) && $disk === static::defaultDisk();
        $size = Arr::get($data, 'size');

        return new static(
            type: AttachmentType::tryFrom((string) Arr::get($data, 'type', '')) ?? AttachmentType::Document,
            mimeType: (string) (Arr::get($data, 'mime_type') ?: static::DEFAULT_MIME_TYPE),
            name: Arr::get($data, 'name'),
            size: $size === null ? null : (int) $size,
            id: Arr::get($data, 'id'),
            url: Arr::get($data, 'url'),
            disk: $stored ? $disk : null,
            path: $stored ? Arr::get($data, 'path') : null,
            unavailable: Arr::get($data, 'unavailable'),
        );
    }

    public function type(): AttachmentType
    {
        return $this->type;
    }

    public function mimeType(): string
    {
        return $this->mimeType;
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function size(): ?int
    {
        return $this->size;
    }

    public function id(): ?string
    {
        return $this->id;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    public function disk(): ?string
    {
        return $this->disk;
    }

    public function path(): ?string
    {
        return $this->path;
    }

    public function unavailable(): ?string
    {
        return $this->unavailable;
    }

    public function withUnavailable(?string $unavailable): static
    {
        $attachment = clone $this;
        $attachment->unavailable = $unavailable;

        return $attachment;
    }

    /**
     * @phpstan-assert-if-true string $this->disk
     * @phpstan-assert-if-true string $this->path
     */
    public function isStored(): bool
    {
        return $this->disk !== null && $this->path !== null;
    }

    public function hasContents(): bool
    {
        return $this->contents !== null;
    }

    public function contents(): string
    {
        if ($this->contents !== null) {
            return $this->contents;
        }

        if ($this->isStored()) {
            return Storage::disk($this->disk)->get($this->path)
                ?? throw new AttachmentException("The attachment [{$this->path}] is missing from the [{$this->disk}] disk.");
        }

        throw new AttachmentException('This attachment has no locally stored contents.');
    }

    public function store(string $path, ?string $disk = null): void
    {
        Storage::disk($disk ?? static::defaultDisk())->put($path, $this->contents());
    }

    public function label(): string
    {
        $name = trim(Str::limit((string) preg_replace('/[^\\p{L}\\p{N} ._-]+/u', '', (string) $this->name), 100, ''));

        return $name === '' ? $this->type->value : $name;
    }

    public function reference(): ?string
    {
        $reference = (string) preg_replace('/[^A-Za-z0-9._-]+/', '', (string) $this->id);

        return $reference === '' ? null : $reference;
    }

    public function mediaType(): string
    {
        return (string) preg_replace('/[^a-z0-9.+\\/-]+/i', '', Str::before($this->mimeType, ';'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'id' => $this->id,
            'name' => $this->name,
            'mime_type' => $this->mimeType,
            'size' => $this->size,
            'url' => $this->url,
            'disk' => $this->disk,
            'path' => $this->path,
            'unavailable' => $this->unavailable,
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
