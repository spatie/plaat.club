<?php

declare(strict_types=1);

namespace Laravel\Converse\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Laravel\Converse\Actions\StageInboundAttachmentsAction;
use Laravel\Converse\BotEvents\Message;
use Laravel\Converse\BotRegistrar;
use Laravel\Converse\Concurrency\ConcurrencyManager;
use Laravel\Converse\Jobs\Concerns\RunsOnConverseQueue;

class HandleInboundEvent implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RunsOnConverseQueue;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(protected string $bot, protected string $chatConnection, protected array $payload)
    {
        $this->onConverseQueue();
    }

    public function bot(): string
    {
        return $this->bot;
    }

    public function chatConnection(): string
    {
        return $this->chatConnection;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function handle(BotRegistrar $registrar, ConcurrencyManager $concurrency, StageInboundAttachmentsAction $action): void
    {
        $bot = $registrar->get($this->bot);

        $driver = $registrar->connection($this->chatConnection, $this->bot);

        $deadline = microtime(true) + max(1.0, $this->timeout / 2);

        foreach ($driver->events($this->payload) as $event) {
            $concurrency->dispatch($bot, $event, fn (Message $message): Message => $action->handle($driver, $message, $deadline));
        }
    }
}
