<?php

declare(strict_types=1);

namespace Laravel\Converse\Actions;

use finfo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Converse\Attachment;
use Laravel\Converse\Attachments\StagingDirectory;
use Laravel\Converse\Attachments\UploadPolicy;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\Contracts\Drivers\Driver;
use Laravel\Converse\Contracts\Drivers\SupportsDownloadAttachments;
use Laravel\Converse\Enums\AttachmentType;
use Laravel\Converse\Exceptions\AttachmentException;
use Laravel\Converse\Exceptions\AttachmentTooLargeException;
use Laravel\Converse\Thread;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

class StageInboundAttachmentsAction
{
    protected const int MAX_NAME_LENGTH = 255;

    protected const string MIME_FILE = 'mime';

    protected const string TOO_LARGE = 'the file is too large';

    public function handle(Driver $driver, Message $message, ?float $deadline = null): Message
    {
        if (! $message->hasAttachments()) {
            return $message;
        }

        if (! $driver instanceof SupportsDownloadAttachments) {
            return $message->withAttachments(collect());
        }

        $policy = UploadPolicy::fromConfig();
        $deadline ??= microtime(true) + max(1.0, (float) config('converse.processing.timeout') / 2);
        $staged = collect();
        $unavailableAttachments = collect();

        foreach ($message->attachments() as $attachment) {
            if ($staged->count() >= $policy->maxFiles()) {
                $unavailableAttachments->push($this->rejected($attachment, 'too many files on one message'));

                continue;
            }

            if (microtime(true) >= $deadline) {
                $unavailableAttachments->push($this->failed($attachment, new AttachmentException('The staging time budget was exhausted.')));

                continue;
            }

            try {
                $stored = $this->store($driver, $message->thread(), $attachment, $policy, $this->timeout($deadline));
            } catch (AttachmentTooLargeException) {
                $stored = $this->rejected($attachment, static::TOO_LARGE);
            } catch (Throwable $throwable) {
                $stored = $this->failed($attachment, $throwable);
            }

            $stored->isStored() ? $staged->push($stored) : $unavailableAttachments->push($stored);
        }

        return $message->withAttachments($staged, $unavailableAttachments);
    }

    protected function store(Driver&SupportsDownloadAttachments $driver, Thread $thread, Attachment $attachment, UploadPolicy $policy, int $timeout): Attachment
    {
        if (! $policy->allows($attachment->mimeType())) {
            return $this->rejected($attachment, $this->unsupported($attachment));
        }

        if ($attachment->size() !== null && ! $policy->permits($attachment->size())) {
            return $this->rejected($attachment, static::TOO_LARGE);
        }

        $disk = Attachment::defaultDisk();

        if (! $this->privateDisk($disk)) {
            return $this->failed($attachment, new AttachmentException("The [{$disk}] attachment disk is publicly reachable."));
        }

        $directory = StagingDirectory::forAttachment($thread, $attachment);
        $reused = $this->reuse($attachment, $disk, $directory, $policy);

        if ($reused instanceof Attachment) {
            return $reused;
        }

        $contents = $driver->downloadAttachment($attachment, $policy->maxBytes(), $timeout);

        if (! $policy->permits(strlen($contents))) {
            return $this->rejected($attachment, static::TOO_LARGE);
        }

        // @codeCoverageIgnoreStart
        $mimeType = new finfo(FILEINFO_MIME_TYPE)->buffer($contents)
            ?: throw new AttachmentException('The media type of the downloaded attachment could not be detected.');
        // @codeCoverageIgnoreEnd

        if (! $policy->allows($mimeType)) {
            return $this->rejected($attachment, $this->unsupported($attachment));
        }

        $path = $directory.'/file.'.$this->extension($mimeType);

        $storage = Storage::disk($disk);

        $storage->put($path, $contents, 'private');
        $storage->put($directory.'/'.static::MIME_FILE, $mimeType, 'private');

        return $this->staged($attachment, $disk, $path, $mimeType, strlen($contents));
    }

    protected function reuse(Attachment $attachment, string $disk, string $directory, UploadPolicy $policy): ?Attachment
    {
        $storage = Storage::disk($disk);

        $path = collect($storage->files($directory))
            ->first(fn (string $file): bool => basename($file) !== static::MIME_FILE);

        if ($path === null) {
            return null;
        }

        $mimeType = trim((string) rescue(fn (): ?string => $storage->get($directory.'/'.static::MIME_FILE), null, false));

        if ($mimeType === '') {
            return null;
        }

        $size = rescue(fn (): int => $storage->size($path), null, false);

        if (! $policy->allows($mimeType)) {
            return $this->rejected($attachment, $this->unsupported($attachment));
        }

        if ($size !== null && ! $policy->permits($size)) {
            return $this->rejected($attachment, static::TOO_LARGE);
        }

        return $this->staged($attachment, $disk, $path, $mimeType, $size);
    }

    protected function timeout(float $deadline): int
    {
        return max(1, min(
            (int) config('converse.attachments.timeout'),
            (int) ceil($deadline - microtime(true)),
        ));
    }

    protected function privateDisk(string $disk): bool
    {
        $config = (array) config("filesystems.disks.{$disk}");

        return ($config['visibility'] ?? null) !== 'public'
            && (($config['driver'] ?? null) !== 'local' || ! isset($config['url']));
    }

    protected function staged(Attachment $attachment, string $disk, string $path, string $mimeType, ?int $size): Attachment
    {
        return new Attachment(
            type: AttachmentType::fromMimeType($mimeType),
            mimeType: $mimeType,
            name: $this->name($attachment),
            size: $size,
            id: $attachment->id(),
            disk: $disk,
            path: $path,
        );
    }

    protected function name(Attachment $attachment): ?string
    {
        if ($attachment->name() === null) {
            return null;
        }

        $name = trim(basename(str_replace('\\', '/', (string) preg_replace('/[\x00-\x1F\x7F]+/', '', $attachment->name()))));
        $name = trim(mb_strcut($name, 0, static::MAX_NAME_LENGTH, 'UTF-8'));

        return $name === '' || trim($name, '.') === '' ? null : $name;
    }

    protected function extension(string $mimeType): string
    {
        return MimeTypes::getDefault()->getExtensions($mimeType)[0]
            ?? ((string) preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::after($mimeType, '/'))) ?: 'bin');
    }

    protected function rejected(Attachment $attachment, string $summary): Attachment
    {
        return $attachment->withUnavailable($summary);
    }

    protected function failed(Attachment $attachment, Throwable $throwable): Attachment
    {
        report($throwable);

        return $this->rejected($attachment, 'it could not be read');
    }

    protected function unsupported(Attachment $attachment): string
    {
        return "this bot cannot read {$attachment->type()->value} files";
    }
}
