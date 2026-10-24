<?php

declare(strict_types=1);

namespace Laravel\Converse\Contracts\Drivers;

use Laravel\Converse\Approval;
use Laravel\Converse\Cards\Card;
use Laravel\Converse\Contracts\BotEvent;
use Laravel\Converse\Markdown;
use Laravel\Converse\SentMessage;
use Laravel\Converse\Thread;
use Laravel\Converse\User;

interface Driver
{
    public function connection(): string;

    public function platform(): string;

    /**
     * @param  array<string, mixed>  $payload
     * @return iterable<int, BotEvent>
     */
    public function events(array $payload): iterable;

    public function sendMessage(Thread $thread, Card|Markdown|string $content): SentMessage;

    public function requestApproval(Thread $thread, Approval $approval): SentMessage;

    /**
     * @param  array<string, bool>  $decisions
     */
    public function resolveApproval(Thread $thread, SentMessage $request, array $decisions): SentMessage;

    public function mention(User|string $user): Markdown;
}
