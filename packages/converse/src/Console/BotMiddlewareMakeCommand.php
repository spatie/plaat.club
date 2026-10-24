<?php

declare(strict_types=1);

namespace Laravel\Converse\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\GeneratorCommand;

#[Signature('make:bot-middleware {name : The name of the middleware}')]
#[Description('Create a new Converse bot middleware class')]
class BotMiddlewareMakeCommand extends GeneratorCommand
{
    /**
     * @var string
     */
    protected $type = 'Bot middleware';

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/bot-middleware.stub';
    }

    /**
     * @param  string  $rootNamespace
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Bots\Middleware';
    }
}
