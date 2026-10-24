<?php

declare(strict_types=1);

namespace Laravel\Converse\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Converse\Contracts\Drivers\IssuesWebhookSecrets;
use Laravel\Converse\ConverseManager;

#[Signature('converse:webhook-secret {connection : The chat platform connection name}
                                    {--env= : The environment variable to write}
                                    {--show : Display the secret without writing the environment file}
                                    {--force : Force the operation to run when in production}')]
#[Description('Generate a webhook secret for a chat platform connection')]
class WebhookSecretCommand extends Command
{
    use ConfirmableTrait;

    public function handle(): int
    {
        $connection = (string) $this->argument('connection');
        $config = config()->array("converse.connections.{$connection}", []);

        if ($config === []) {
            $this->components->error("Converse connection [{$connection}] is not defined.");

            return static::FAILURE;
        }

        $driver = $this->laravel->make(ConverseManager::class)->instance($connection);

        if ($driver instanceof IssuesWebhookSecrets) {
            $this->components->error($driver->webhookSecretHint());

            return static::FAILURE;
        }

        $secret = Str::random(64);
        $current = (string) ($config['webhook_secret'] ?? '');

        if ($this->option('show')) {
            $this->line($secret);

            return static::SUCCESS;
        }

        if ($current !== '' && ! $this->confirmToProceed()) {
            return static::FAILURE;
        }

        $variable = $this->variable($connection);

        if ($variable === null) {
            $this->components->error('The [--env] option must name a valid environment variable.');

            return static::FAILURE;
        }

        if (! $this->write($variable, $secret)) {
            return static::FAILURE;
        }

        config(["converse.connections.{$connection}.webhook_secret" => $secret]);

        $this->components->info("Webhook secret set on [{$variable}].");

        if ($current !== '') {
            $this->components->warn('Register the new secret with the platform before the next webhook delivery.');
        }

        return static::SUCCESS;
    }

    protected function variable(string $connection): ?string
    {
        $variable = (string) $this->option('env');

        if ($variable === '') {
            return Str::upper(str_replace('-', '_', Str::snake($connection))).'_WEBHOOK_SECRET';
        }

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $variable) === 1 ? $variable : null;
    }

    protected function write(string $variable, string $secret): bool
    {
        $path = $this->laravel->environmentFilePath();

        if (! File::isFile($path)) {
            $this->components->error("No environment file was found at [{$path}].");

            return false;
        }

        $contents = File::get($path);
        $replaced = (string) preg_replace_callback('/^'.preg_quote($variable, '/').'=.*$/m', fn (): string => $variable.'='.$secret, $contents, 1);

        if ($replaced === $contents) {
            $replaced = rtrim($contents, PHP_EOL).PHP_EOL.$variable.'='.$secret.PHP_EOL;
        }

        if (File::put($path, $replaced) !== strlen($replaced)) {
            $this->components->error("Unable to write the environment file at [{$path}].");

            return false;
        }

        return true;
    }
}
