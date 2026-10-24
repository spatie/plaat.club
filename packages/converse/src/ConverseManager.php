<?php

declare(strict_types=1);

namespace Laravel\Converse;

use Illuminate\Support\MultipleInstanceManager;
use Laravel\Converse\Drivers\Github\GithubDriver;
use Laravel\Converse\Drivers\Slack\SlackDriver;
use Laravel\Converse\Drivers\Telegram\TelegramDriver;
use Laravel\Converse\Drivers\Terminal\TerminalDriver;
use Laravel\Converse\Drivers\Web\WebDriver;
use Laravel\Converse\Exceptions\InvalidConnectionException;
use Laravel\Converse\Models\Message;
use Laravel\Converse\Models\Run;
use Laravel\Converse\Models\Thread;
use Laravel\Converse\Models\ThreadEvent;

class ConverseManager extends MultipleInstanceManager
{
    /** @var array<string, string> */
    protected const array TERMINAL = ['driver' => 'terminal'];

    /** @var class-string<Thread> */
    protected static string $threadModel = Thread::class;

    /** @var class-string<Message> */
    protected static string $messageModel = Message::class;

    /** @var class-string<Run> */
    protected static string $runModel = Run::class;

    /** @var class-string<ThreadEvent> */
    protected static string $threadEventModel = ThreadEvent::class;

    /** @return class-string<Thread> */
    public static function threadModel(): string
    {
        return static::$threadModel;
    }

    /** @param  class-string<Thread>  $model */
    public static function useThreadModel(string $model): void
    {
        static::$threadModel = $model;
    }

    /** @return class-string<Message> */
    public static function messageModel(): string
    {
        return static::$messageModel;
    }

    /** @param  class-string<Message>  $model */
    public static function useMessageModel(string $model): void
    {
        static::$messageModel = $model;
    }

    /** @return class-string<Run> */
    public static function runModel(): string
    {
        return static::$runModel;
    }

    /** @param  class-string<Run>  $model */
    public static function useRunModel(string $model): void
    {
        static::$runModel = $model;
    }

    /** @return class-string<ThreadEvent> */
    public static function threadEventModel(): string
    {
        return static::$threadEventModel;
    }

    /** @param  class-string<ThreadEvent>  $model */
    public static function useThreadEventModel(string $model): void
    {
        static::$threadEventModel = $model;
    }

    public function getDefaultInstance(): never
    {
        throw new InvalidConnectionException('Converse does not support a default connection. Request one by name.');
    }

    /**
     * @param  string  $name
     */
    public function setDefaultInstance($name): never
    {
        throw new InvalidConnectionException('Converse does not support a default connection.');
    }

    /**
     * @param  string  $name
     * @return array<string, mixed>
     */
    public function getInstanceConfig($name): array
    {
        $config = $this->config->get("converse.connections.{$name}", $name === TerminalDriver::CONNECTION ? static::TERMINAL : null);

        if (! is_array($config)) {
            throw new InvalidConnectionException("Converse connection [{$name}] is not defined.");
        }

        return ['name' => $name] + $config;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createGithubDriver(array $config): GithubDriver
    {
        return new GithubDriver($config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createSlackDriver(array $config): SlackDriver
    {
        return new SlackDriver($config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createTelegramDriver(array $config): TelegramDriver
    {
        return new TelegramDriver($config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createTerminalDriver(array $config): TerminalDriver
    {
        return new TerminalDriver($config);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function createWebDriver(array $config): WebDriver
    {
        return new WebDriver($config);
    }
}
