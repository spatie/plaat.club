<?php

declare(strict_types=1);

namespace Laravel\Converse\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Laravel\Ai\AiServiceProvider;
use Laravel\Converse\Drivers\Web\WebChatOptions;
use Laravel\Converse\Exceptions\ConverseException;
use Laravel\Converse\Facades\Converse;
use ReflectionClass;
use Symfony\Component\Process\Process as SymfonyProcess;

use function Laravel\Prompts\confirm;

#[Signature('converse:install
                {--force : Overwrite a chat page that already exists}
                {--without-web : Do not scaffold the web chat}
                {--without-node : Do not install the Node dependencies}
                {--without-migration-prompt : Do not prompt to run pending migrations}')]
#[Description('Install Converse and scaffold a web chat')]
class InstallCommand extends Command
{
    protected const string BOT = 'assistant';

    protected const string BOT_CLASS = 'Assistant';

    /**
     * @var array<string, array{page: string, extension: string, inertia: string}>
     */
    protected const array FRAMEWORKS = [
        'vue' => ['page' => 'Chat', 'extension' => 'vue', 'inertia' => '@inertiajs/vue3'],
        'react' => ['page' => 'chat', 'extension' => 'tsx', 'inertia' => '@inertiajs/react'],
    ];

    public function handle(Filesystem $files): int
    {
        $web = ! $this->option('without-web') && confirm('Do you plan to use Converse to build a web chat with Vue or React?', default: true, hint: 'Choose no if your bots only live on platforms such as Slack or Telegram.');

        $this->call('vendor:publish', ['--tag' => ['converse-config', 'converse-bots']]);

        $this->publishMigrations($files);

        $this->installBot($files, $web);

        if ($web) {
            $this->scaffoldPage($files);

            $this->warnAboutQueueRetries();
        }

        if (! $this->option('without-migration-prompt') && confirm('Would you like to run the database migrations now?', default: true)) {
            $this->call('migrate');
        }

        $this->remindAboutProviderCredentials();

        return static::SUCCESS;
    }

    protected function remindAboutProviderCredentials(): void
    {
        $provider = Config::string('ai.default');

        if (Config::get("ai.providers.{$provider}.key") !== null) {
            return;
        }

        $this->components->info('Converse is installed. Next, configure an AI provider so the ['.static::BOT."] bot can reply. Your default provider is [{$provider}], but you may use any provider the AI SDK supports: https://laravel.com/docs/ai-sdk#configuration");
    }

    protected function warnAboutQueueRetries(): void
    {
        try {
            WebChatOptions::fromConfig(['name' => 'web'] + Config::array('converse.connections.web', []))->assertQueueOutlastsTurn();
        } catch (ConverseException $converseException) {
            $this->components->warn($converseException->getMessage());
        }
    }

    protected function publishMigrations(Filesystem $files): void
    {
        $this->publishConverseMigration($files);

        $this->publishAgentConversationsMigration($files);
    }

    protected function publishConverseMigration(Filesystem $files): void
    {
        if ($this->migrationExists($files, 'create_converse_tables')) {
            $this->components->info('The Converse migration is already published.');

            return;
        }

        $this->call('vendor:publish', ['--tag' => 'converse-migrations']);
    }

    protected function publishAgentConversationsMigration(Filesystem $files): void
    {
        if ($this->migrationExists($files, 'create_agent_conversations_table')) {
            $this->components->info('The Laravel AI conversation migration is already published.');

            return;
        }

        $table = Config::string('ai.conversations.tables.conversations', 'agent_conversations');

        if ($this->tableExists($table)) {
            $this->components->info("The [{$table}] table already exists, so the Laravel AI conversation migration was not published.");

            return;
        }

        $migrations = dirname((string) new ReflectionClass(AiServiceProvider::class)->getFileName(), 2).'/database/migrations';

        foreach ($files->glob($migrations.'/*_create_agent_conversations_table.php') as $migration) {
            $path = database_path('migrations/'.Date::now()->format('Y_m_d_His').'_create_agent_conversations_table.php');

            $files->copy($migration, $path);

            $this->components->info("Published the Laravel AI conversation migration to [{$path}].");
        }
    }

    protected function migrationExists(Filesystem $files, string $name): bool
    {
        return $files->glob(database_path("migrations/*_{$name}.php")) !== [];
    }

    protected function tableExists(string $table): bool
    {
        try {
            return Schema::connection(Config::get('ai.conversations.connection'))->hasTable($table);
        } catch (QueryException) {
            return false;
        }
    }

    protected function installBot(Filesystem $files, bool $web): void
    {
        if (! $files->exists(app_path('Ai/Agents/'.static::BOT_CLASS.'.php'))) {
            $this->call('make:bot', ['name' => static::BOT_CLASS, '--agent' => true]);
        }

        $routes = base_path('routes/bot.php');
        $contents = $files->get($routes);

        $registration = $this->stub($files, 'bot-registration', [
            'bot' => static::BOT,
            'class' => static::BOT_CLASS,
            'connections' => $web ? "'web',\n        " : '',
        ]);

        if (str_contains($contents, trim($registration))) {
            $this->components->info('The ['.static::BOT.'] bot is already registered in [routes/bot.php].');

            return;
        }

        if (str_contains($contents, "'".static::BOT."'")) {
            if (! $web) {
                $this->components->info('An ['.static::BOT.'] bot is already registered in [routes/bot.php].');

                return;
            }

            $this->components->warn('An ['.static::BOT."] bot is already registered in [routes/bot.php]. Bind it to the web connection with ->connection('web').");

            return;
        }

        $imports = [$this->laravel->getNamespace().'Ai\\Agents\\'.static::BOT_CLASS, Converse::class];

        $files->put($routes, $this->withImports($contents, $imports).$registration);

        $this->components->info('Registered the ['.static::BOT.'] bot in [routes/bot.php].');
    }

    protected function scaffoldPage(Filesystem $files): void
    {
        $framework = $this->framework();

        if ($framework === null) {
            $this->components->warn('No Vue or React dependency was found in [package.json], so no chat page was published.');

            return;
        }

        if (! $this->packageJsonHas(static::FRAMEWORKS[$framework]['inertia'])) {
            $this->components->warn("No Inertia adapter was found in [package.json], so no chat page was published. Install [@laravel/converse-{$framework}] and render its <Chat> component with the payload of Converse::chat('".static::BOT."').");

            return;
        }

        $this->publishPage($files, $framework);
        $this->installNodeDependencies($framework);
    }

    protected function publishPage(Filesystem $files, string $framework): void
    {
        ['page' => $page, 'extension' => $extension] = static::FRAMEWORKS[$framework];

        $path = resource_path("js/pages/{$page}.{$extension}");

        if ($files->exists($path) && ! $this->option('force')) {
            $this->components->warn("A chat page already exists at [{$path}].");
        } else {
            $files->ensureDirectoryExists(dirname($path));
            $files->put($path, $this->stub($files, "chat.{$extension}"));

            $this->components->info("Published the chat page to [{$path}].");
        }

        $this->registerRoute($files, $page);
    }

    protected function registerRoute(Filesystem $files, string $page): void
    {
        $routes = base_path('routes/web.php');

        if (! $files->exists($routes)) {
            $this->components->warn("No [routes/web.php] was found, so no chat route was added. Render the page with Converse::chat('".static::BOT."') as its [chat] prop.");

            return;
        }

        $hasChatRoute = str_contains($files->get($routes), "Converse::chat('".static::BOT."'");
        $hasNewConversationRoute = str_contains($files->get($routes), "Route::post('chat/new'");

        if ($hasChatRoute && $hasNewConversationRoute) {
            $this->components->info('The [/chat] routes are already in [routes/web.php].');

            return;
        }

        if ($hasChatRoute || $hasNewConversationRoute) {
            $this->components->warn('Only part of the chat routes is in [routes/web.php]. Add both [GET /chat] and [POST /chat/new] as the Converse README shows.');

            return;
        }

        $contents = $this->withImports($files->get($routes), [Request::class, Inertia::class, Converse::class]);

        $files->put($routes, $contents.$this->stub($files, 'route', ['uri' => 'chat', 'page' => $page, 'bot' => static::BOT]));

        $this->components->info('Added the [/chat] and [/chat/new] routes to [routes/web.php].');
        $this->components->info('Visit [/chat], or link it from your navigation.');
    }

    /**
     * @param  array<int, string>  $classes
     */
    protected function withImports(string $contents, array $classes): string
    {
        preg_match_all('/^use ([\\w\\\\]+);\n/m', $contents, $matches);

        $imports = collect($matches[1])
            ->merge($classes)
            ->unique()
            ->sort(fn (string $first, string $second): int => strcasecmp(str_replace('\\', ' ', $first), str_replace('\\', ' ', $second)))
            ->map(fn (string $class): string => "use {$class};\n")
            ->implode('');

        if ($matches[0] === []) {
            return rtrim((string) preg_replace('/^<\?php\s*/', "<?php\n\n{$imports}\n", $contents, 1))."\n";
        }

        $position = (int) strpos($contents, $matches[0][0]);

        return substr_replace((string) preg_replace('/^use [\\w\\\\]+;\n/m', '', $contents), $imports, $position, 0);
    }

    protected function installNodeDependencies(string $framework): void
    {
        if ($this->option('without-node') || ! confirm('Would you like to install and build the Node dependencies?', default: true)) {
            return;
        }

        $package = "@laravel/converse-{$framework}";

        $commands = match (true) {
            $this->hasLockFile('yarn.lock') => ["yarn add {$package} --ignore-scripts", 'yarn run build'],
            $this->hasLockFile('pnpm-lock.yaml') => ["pnpm add {$package} --ignore-scripts", 'pnpm run build'],
            $this->hasLockFile('bun.lock', 'bun.lockb') => ["bun add {$package} --ignore-scripts", 'bun run build'],
            default => ["npm install {$package} --ignore-scripts", 'npm run build'],
        };

        $command = implode(' && ', $commands);

        if (Process::path(base_path())->tty(SymfonyProcess::isTtySupported())->run($command)->failed()) {
            $this->components->warn("Node dependency installation failed. Please run the following command manually: {$command}");

            return;
        }

        $this->components->info('Node dependencies installed successfully.');
    }

    protected function framework(): ?string
    {
        return match (true) {
            $this->packageJsonHas('@inertiajs/vue3') => 'vue',
            $this->packageJsonHas('@inertiajs/react') => 'react',
            $this->packageJsonHas('vue') => 'vue',
            $this->packageJsonHas('react') => 'react',
            default => null,
        };
    }

    protected function packageJsonHas(string $package): bool
    {
        $path = base_path('package.json');

        if (! file_exists($path)) {
            return false;
        }

        $packageJson = (array) json_decode((string) file_get_contents($path), true);

        return isset($packageJson['dependencies'][$package]) || isset($packageJson['devDependencies'][$package]);
    }

    protected function hasLockFile(string ...$names): bool
    {
        return array_any($names, fn (string $name): bool => file_exists(base_path($name)));
    }

    /**
     * @param  array<string, string>  $replacements
     */
    protected function stub(Filesystem $files, string $name, array $replacements = []): string
    {
        $contents = $files->get(__DIR__.'/../../stubs/converse/'.$name.'.stub');

        foreach ($replacements as $key => $value) {
            $contents = str_replace('{{ '.$key.' }}', $value, $contents);
        }

        return $contents;
    }
}
