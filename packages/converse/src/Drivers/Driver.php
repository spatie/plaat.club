<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers;

use Closure;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Container\Container;
use Laravel\Converse\Contracts\Drivers\Driver as DriverContract;
use Laravel\Converse\Drivers\Concerns\RendersApprovalCards;
use Laravel\Converse\Markdown;
use Laravel\Converse\Support\AttachmentDownloader;
use Laravel\Converse\Thread;
use Laravel\Converse\ThreadContext;
use Laravel\Converse\User;

abstract class Driver implements DriverContract
{
    use RendersApprovalCards;

    protected const int API_TIMEOUT = 30;

    protected ?CurlHandler $handler = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(protected array $config) {}

    public function connection(): string
    {
        return (string) ($this->config['name'] ?? '');
    }

    public function platform(): string
    {
        return (string) ($this->config['driver'] ?? $this->connection());
    }

    public function mention(User|string $user): Markdown
    {
        return Markdown::make($this->userId($user));
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function forThread(Thread $thread, Closure $callback): mixed
    {
        return Container::getInstance()->make(ThreadContext::class)->for($thread, $callback);
    }

    protected function handler(): CurlHandler
    {
        return $this->handler ??= new CurlHandler;
    }

    protected function downloader(): AttachmentDownloader
    {
        return Container::getInstance()->make(AttachmentDownloader::class);
    }

    protected function token(string $configKey): string
    {
        return (string) ($this->config[$configKey] ?? '');
    }

    protected function userId(User|string $user): string
    {
        return $user instanceof User ? $user->id() : $user;
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function splitCommand(string $text): array
    {
        $length = strcspn($text, " \t\n\r\0\x0B");

        return [substr($text, 0, $length), substr($text, $length)];
    }
}
