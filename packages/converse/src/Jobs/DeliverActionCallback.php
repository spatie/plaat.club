<?php

declare(strict_types=1);

namespace Laravel\Converse\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Uri;
use Laravel\Converse\BotEvents\Action;
use Laravel\Converse\Exceptions\InvalidActionCallbackException;

class DeliverActionCallback implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(protected string $url, protected Action $action, protected string $deliveryId)
    {
        $this->onConnection(config('converse.callbacks.queue.connection') ?? config('converse.queue.connection'));
        $this->onQueue(config('converse.callbacks.queue.name') ?? config('converse.queue.name'));
    }

    public function url(): string
    {
        return $this->url;
    }

    public function action(): Action
    {
        return $this->action;
    }

    public function deliveryId(): string
    {
        return $this->deliveryId;
    }

    public function tries(): int
    {
        return config()->integer('converse.callbacks.tries', 3);
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return array_map(intval(...), array_values(config()->array('converse.callbacks.backoff', [10, 60, 300])));
    }

    public static function signingSecret(): string
    {
        $secret = config('converse.callbacks.signing_secret');

        if (! is_string($secret) || $secret === '') {
            throw InvalidActionCallbackException::missingSigningSecret();
        }

        return $secret;
    }

    public function handle(): void
    {
        try {
            $secret = static::signingSecret();
        } catch (InvalidActionCallbackException $invalidActionCallbackException) {
            $this->fail($invalidActionCallbackException);

            throw $invalidActionCallbackException;
        }

        $resolution = $this->validateDestination();

        $payload = [
            'type' => 'action',
            'actionId' => $this->action->actionId(),
            'value' => $this->action->value(),
            'user' => ['id' => $this->action->user()->id(), 'name' => $this->action->user()->name()],
            'threadId' => $this->action->thread()->encode(),
            'messageId' => $this->action->messageId(),
            'deliveryId' => $this->deliveryId,
        ];
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $nonce = Str::random(32);
        $signature = hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, $secret);

        Http::withBody($body, 'application/json')
            ->withHeaders([
                'Converse-Delivery' => $this->deliveryId,
                'Converse-Timestamp' => $timestamp,
                'Converse-Nonce' => $nonce,
                'Converse-Signature' => 'v1='.$signature,
            ])
            ->withoutRedirecting()
            ->withOptions(['curl' => [CURLOPT_RESOLVE => [$resolution]]])
            ->timeout(config()->integer('converse.callbacks.timeout', 10))
            ->throw()
            ->post($this->url);
    }

    protected function validateDestination(): string
    {
        $uri = Uri::of($this->url);
        $host = (string) $uri->host();

        if ($host === '' || $uri->scheme() !== 'https') {
            throw new InvalidActionCallbackException("Action callback destination [{$this->url}] must use HTTPS.");
        }

        $addresses = gethostbynamel($host);

        if ($addresses === false || array_any($addresses, fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false)) {
            throw new InvalidActionCallbackException("Action callback destination [{$host}] must resolve to a public IP address.");
        }

        return $host.':'.($uri->port() ?? 443).':'.implode(',', $addresses);
    }
}
