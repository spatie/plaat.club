<?php

declare(strict_types=1);

namespace Laravel\Converse\Drivers\Web;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\ProvidesInertiaProperty;
use Laravel\Converse\AgentBot;
use Laravel\Converse\Bot;
use Laravel\Converse\BotRegistrar;
use Laravel\Converse\Contracts\ConversationStore;
use Laravel\Converse\Contracts\RunStore;
use Laravel\Converse\Thread;

class WebChat
{
    public function __construct(
        protected BotRegistrar $registrar,
        protected ConversationStore $conversations,
        protected RunStore $runs,
        protected WebThreadSnapshot $snapshots,
        protected WebTranscript $transcript,
        protected Request $request,
    ) {}

    public function for(Bot $bot, bool $new = false, ?string $connection = null): ChatProps
    {
        $driver = $this->registrar->webConnection($bot->name(), $connection);

        $owner = $driver->owner($this->request, $this->registrar->webUser($this->request, $driver), $bot) ?? throw new AuthenticationException;

        $thread = $driver->thread($owner, $this->threadId($bot, $driver, $owner, $new), $bot->name());

        if (! $this->rendersWithInertia()) {
            return new ChatProps($bot, $driver, $thread, $this->snapshots, $this->transcript);
        }

        return new InertiaChatProps($bot, $driver, $thread, $this->snapshots, $this->transcript);
    }

    protected function rendersWithInertia(): bool
    {
        return interface_exists(ProvidesInertiaProperty::class);
    }

    protected function threadId(Bot $bot, WebDriver $driver, string $owner, bool $new): string
    {
        if ($new) {
            return (string) Str::uuid7();
        }

        return $this->currentThreadId($bot, $driver, $owner) ?? (string) Str::uuid7();
    }

    protected function currentThreadId(Bot $bot, WebDriver $driver, string $owner): ?string
    {
        $channel = $driver->thread($owner, null, $bot->name());

        if (! $bot instanceof AgentBot) {
            return $this->currentHandlerThreadId($channel);
        }

        return $this->currentAgentThreadId($channel);
    }

    protected function currentHandlerThreadId(Thread $channel): ?string
    {
        $newest = $this->conversations->messages($channel, 1)->first();

        return $newest?->thread()->id();
    }

    protected function currentAgentThreadId(Thread $channel): ?string
    {
        return $this->runs->threadWithLatestRun($channel)?->id();
    }
}
