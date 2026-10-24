<?php

declare(strict_types=1);

namespace Laravel\Converse\Routing;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Converse\ActionCallback;
use Laravel\Converse\BotEvents\Action;
use Laravel\Converse\Cards\ActionElement;
use Laravel\Converse\Cards\Actions;
use Laravel\Converse\Cards\Button;
use Laravel\Converse\Cards\Card;
use Laravel\Converse\Cards\CardElement;
use Laravel\Converse\Cards\Section;
use Laravel\Converse\Concerns\BuildsCacheKeys;
use Laravel\Converse\Exceptions\InvalidActionCallbackException;
use Laravel\Converse\Jobs\DeliverActionCallback;
use Laravel\Converse\Thread;
use Laravel\Converse\ThreadContext;

class CallbackRegistry
{
    use BuildsCacheKeys;

    public const string PREFIX = '__cb:';

    public function __construct(protected Repository $cache, protected ThreadContext $context) {}

    public function prepare(Card $card, ?string $platform = null): Card
    {
        $card->validate();

        return $platform !== null && array_key_exists($platform, $card->raw()) ? $card : $this->register($card);
    }

    protected function register(Card $card): Card
    {
        return $card->withChildren(...$this->registerElements(
            $card->children(),
            $this->context->thread()?->forChannel(),
            $this->context->bot(),
        ));
    }

    public function resolve(Action $action): ?ActionCallback
    {
        $token = $this->token($action->value());

        if ($token === null) {
            return null;
        }

        $callback = $this->get($token);

        if (! $callback instanceof ActionCallback || ! $callback->matches($action)) {
            return null;
        }

        if ($callback->isOnce() && ! $this->cache->forget($this->key($token))) {
            return null;
        }

        return $callback;
    }

    protected function token(?string $value): ?string
    {
        return $value !== null && Str::startsWith($value, static::PREFIX) ? Str::after($value, static::PREFIX) : null;
    }

    protected function get(string $token): ?ActionCallback
    {
        $callback = rescue(fn (): mixed => $this->cache->get($this->key($token)), report: false);

        return $callback instanceof ActionCallback ? $callback : null;
    }

    /**
     * @param  Collection<int, CardElement>  $elements
     * @return Collection<int, CardElement>
     */
    protected function registerElements(Collection $elements, ?Thread $channel, ?string $bot): Collection
    {
        return $elements->map(function (CardElement $element) use ($channel, $bot): CardElement {
            if ($element instanceof Section) {
                return $element->withChildren(...$this->registerElements($element->children(), $channel, $bot));
            }

            if ($element instanceof Actions) {
                return $element->withChildren(...$element->children()->map(
                    fn (ActionElement $action): ActionElement => $this->registerAction($action, $channel, $bot),
                ));
            }

            return $element;
        });
    }

    protected function registerAction(ActionElement $action, ?Thread $channel, ?string $bot): ActionElement
    {
        if (! $action instanceof Button || $action->callbackUrl() === null) {
            return $action;
        }

        if (! $channel instanceof Thread) {
            throw new InvalidActionCallbackException('A portable card callback button may only be rendered while sending to a thread.');
        }

        DeliverActionCallback::signingSecret();

        $token = Str::random(32);

        $this->cache->put(
            $this->key($token),
            new ActionCallback($action->callbackUrl(), $action->value(), $channel, $action->id(), $bot, $action->isOnce()),
            config()->integer('converse.callbacks.ttl', 60 * 60 * 24 * 30),
        );

        return $action->withValue(static::PREFIX.$token);
    }

    protected function key(string $token): string
    {
        return $this->cacheKey('callback', null, $token);
    }
}
