<?php

declare(strict_types=1);

namespace Laravel\Converse\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\GeneratorCommand;

#[Signature('make:bot
                {name : The name of the bot}
                {--agent : Create a Laravel AI agent that remembers its conversations}')]
#[Description('Create a new Converse bot handler class')]
class BotMakeCommand extends GeneratorCommand
{
    /**
     * @var string
     */
    protected $type = 'Bot';

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/'.($this->option('agent') ? 'bot.agent' : 'bot').'.stub';
    }

    /**
     * @param  string  $rootNamespace
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.($this->option('agent') ? '\Ai\Agents' : '\Bots');
    }
}
