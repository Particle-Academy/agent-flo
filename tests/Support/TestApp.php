<?php

declare(strict_types=1);

namespace AgentFlo\Tests\Support;

use Illuminate\Bus\BusServiceProvider;
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Queue\QueueServiceProvider;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Prism\Harness\PrismHarnessServiceProvider;
use Prism\Prism\PrismManager;
use Prism\Prism\PrismServiceProvider;

/**
 * A hand-built Laravel 13 kernel: the framework this package integrates with,
 * booted directly, with no testbench. In-memory SQLite, array cache, sync queue,
 * and the scripted provider, so nothing leaves the process and no model is called.
 *
 * The temp folder is per process, so two suites never share one.
 */
final class TestApp
{
    public static ?ScriptedProvider $provider = null;

    /** @param array<string, mixed> $config dotted keys merged over the defaults */
    public static function boot(array $config = [], ?ScriptedProvider $provider = null): Application
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'agent-flo-tests-'.getmypid();
        if (! is_dir($base.'/config')) {
            mkdir($base.'/config', 0777, true);
        }
        $app = new Application($base);
        $app->useConfigPath($base.'/config');
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $repository = new Repository([
            'app' => ['key' => 'base64:'.base64_encode(str_repeat('k', 32)), 'cipher' => 'AES-256-CBC', 'env' => 'testing', 'debug' => false],
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]],
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array', 'serialize' => false]]],
            'queue' => ['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]],
            'logging' => ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class]]],
            'fancy-flow-mcp' => ['local' => ['enabled' => false], 'store' => ['driver' => 'array']],
            'prism-harness' => ['provider' => 'scripted', 'model' => 'test-model', 'agent' => ['default' => 'assistant', 'modes' => [
                'assistant' => ['system_prompt' => 'You help.', 'tools' => [], 'skills' => [], 'max_steps' => 4],
            ]]],
        ]);
        foreach ($config as $key => $value) {
            $repository->set($key, $value);
        }
        $app->instance('config', $repository);

        foreach ([FilesystemServiceProvider::class, DatabaseServiceProvider::class, CacheServiceProvider::class,
            BusServiceProvider::class, QueueServiceProvider::class, PrismServiceProvider::class,
            PrismHarnessServiceProvider::class, \FancyFlow\Laravel\FancyFlowServiceProvider::class,
            \AgentFlo\Laravel\AgentFloServiceProvider::class] as $serviceProvider) {
            $app->register($serviceProvider);
        }

        Model::unguard();
        self::migrate(dirname(__DIR__, 2).'/vendor/particle-academy/prism-harness/database/migrations');
        self::migrate(dirname(__DIR__, 2).'/vendor/particle-academy/fancy-flow-php/database/migrations');
        self::migrate(dirname(__DIR__, 2).'/database/migrations');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        self::$provider = $provider ?? new ScriptedProvider;
        $scripted = self::$provider;
        $app->make(PrismManager::class)->extend('scripted', fn () => $scripted);
        $app->boot();

        return $app;
    }

    private static function migrate(string $directory): void
    {
        $files = glob($directory.'/*.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            (require $file)->up();
        }
    }
}
