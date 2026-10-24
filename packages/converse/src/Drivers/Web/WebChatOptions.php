<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Web;

use Laravel\Converse\Enums\WebExecution;
use Laravel\Converse\Enums\WebTransport;
use Laravel\Converse\Exceptions\ConverseException;

class WebChatOptions
{
    /**
     * @param  list<string>  $middleware
     */
    public function __construct(
        protected array $middleware,
        protected ?string $guard,
        protected bool $guests,
        protected WebExecution $execution,
        protected int $runTimeoutSeconds,
        protected int $idleTimeoutSeconds,
        protected float $streamIntervalSeconds,
        protected int $streamHeartbeatSeconds,
        protected WebTransport $transport,
        protected float $pollIntervalSeconds,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): static
    {
        return new static(
            middleware: array_values(array_map(strval(...), (array) ($config['middleware'] ?? ['web']))),
            guard: is_string($config['guard'] ?? null) ? $config['guard'] : null,
            guests: filter_var($config['guests'] ?? false, FILTER_VALIDATE_BOOL),
            execution: WebExecution::fromConfig($config),
            runTimeoutSeconds: (int) ($config['run_timeout'] ?? 60),
            idleTimeoutSeconds: (int) ($config['idle_timeout'] ?? 30),
            streamIntervalSeconds: (float) ($config['stream_interval'] ?? 0.1),
            streamHeartbeatSeconds: (int) ($config['stream_heartbeat'] ?? 2),
            transport: WebTransport::fromConfig($config),
            pollIntervalSeconds: (float) ($config['poll_interval'] ?? 2),
        );
    }

    /**
     * @return list<string>
     */
    public function middleware(): array
    {
        return $this->middleware;
    }

    public function guard(): ?string
    {
        return $this->guard;
    }

    public function allowsGuests(): bool
    {
        return $this->guests;
    }

    public function runTimeoutSeconds(): int
    {
        return $this->runTimeoutSeconds;
    }

    public function idleTimeoutSeconds(): int
    {
        return $this->idleTimeoutSeconds;
    }

    public function streamIntervalMilliseconds(): int
    {
        return max(1, (int) round($this->streamIntervalSeconds * 1000));
    }

    public function streamHeartbeatMilliseconds(): int
    {
        return max(0, $this->streamHeartbeatSeconds * 1000);
    }

    public function transport(): WebTransport
    {
        return $this->transport;
    }

    public function pollIntervalMilliseconds(): int
    {
        return max(1, (int) round($this->pollIntervalSeconds * 1000));
    }

    public function execution(): WebExecution
    {
        return $this->execution;
    }

    public function expirySeconds(): int
    {
        return $this->runTimeoutSeconds + $this->idleTimeoutSeconds;
    }

    public function followWindowMilliseconds(): int
    {
        return max(1, $this->idleTimeoutSeconds) * 1000;
    }

    public function withTransport(WebTransport $transport, ?float $intervalSeconds = null): static
    {
        $options = clone $this;
        $options->transport = $transport;

        if ($intervalSeconds === null) {
            return $options;
        }

        if ($transport === WebTransport::Poll) {
            $options->pollIntervalSeconds = $intervalSeconds;

            return $options;
        }

        $options->streamIntervalSeconds = $intervalSeconds;

        return $options;
    }

    public function assertQueueOutlastsTurn(): void
    {
        if ($this->execution() === WebExecution::AfterResponse) {
            return;
        }

        $connection = config('converse.queue.connection') ?? config('queue.default');
        $retryAfter = config("queue.connections.{$connection}.retry_after");

        if (! is_numeric($retryAfter) || (int) $retryAfter > $this->runTimeoutSeconds) {
            return;
        }

        throw new ConverseException("Queue connection [{$connection}] retries jobs after [{$retryAfter}] seconds, within a web turn's [{$this->runTimeoutSeconds}] second run timeout. Raise its [retry_after] above the run timeout.");
    }
}
