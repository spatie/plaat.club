<?php

declare(strict_types=1);

namespace Laravel\Converse\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Converse\BotRegistrar;
use Laravel\Converse\Contracts\Drivers\ReceivesWebhooks;
use Laravel\Converse\Contracts\Drivers\SupportsCreateInlineResponses;
use Laravel\Converse\Jobs\HandleInboundEvent;
use Symfony\Component\HttpFoundation\Response;

class WebhookController
{
    public function __construct(protected BotRegistrar $registrar) {}

    public function __invoke(Request $request, string $bot, string $connection): Response
    {
        abort_unless($this->registrar->has($bot) && $this->registrar->get($bot)->hasConnection($connection), 404);

        $driver = $this->registrar->connection($connection);

        abort_unless($driver instanceof ReceivesWebhooks && $driver->verify($request), 404);

        $response = $driver instanceof SupportsCreateInlineResponses ? $driver->createInlineResponse($request) : null;

        if ($response instanceof Response) {
            return $response;
        }

        if ($request->isMethod('POST')) {
            HandleInboundEvent::dispatch($bot, $driver->connection(), $this->payload($request));
        }

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Request $request): array
    {
        return $request->isJson() ? $request->json()->all() : (array) $request->post();
    }
}
