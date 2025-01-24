<?php

namespace Nuimarkets\LaravelSharedUtils\Testing;

use Exception;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use PHPUnit\Runner\BeforeFirstTestHook;

/**
 * DB Setup Extension for phpUnit to drop/create testing database with migrations run
 */
class DBSetupExtension implements
    BeforeFirstTestHook
{
    use CreatesApplication;

    public function executeBeforeFirstTest(): void
    {

        if (getenv("DB_SETUP") !== "1") {
            return;
        }

        $startTime = microtime(true);

        $app = $this->createApplication();

        Log::warning("Running DB Setup (fresh DB + migrations/seeders)");


        $app->bootstrapWith([
            \Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
        ]);

        $app->singleton('db.factory', function () use ($app) {
            return new \Illuminate\Database\Connectors\ConnectionFactory($app);
        });

        app()->singleton('db', function () use ($app) {
            return new DatabaseManager($app, $app['db.factory']);
        });

        $app->singleton(
            \Illuminate\Contracts\Debug\ExceptionHandler::class,
            \App\Exceptions\Handler::class
        );

        // Set the application instance for Facades
        Facade::setFacadeApplication($app);

        $this->setTemporaryDefaultConnection();
        $this->resetDatabase();

        app('db')->setDefaultConnection(env('DB_CONNECTION'));

        $testing = Config::get('database.connections.testing');

        Log::debug("Testing connection", [$testing]);


        try {
            Log::info("Running migrate:fresh");
            Artisan::call('migrate:fresh');
        } catch (Exception $exception) {
            Log::error("Running 'migrate:fresh' failed", [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
            throw $exception;
        }

        $this->verifyMigrations();

        try {
            Log::info("Running db:seed");
            Artisan::call('db:seed');
        } catch (Exception $exception) {
            Log::error("Running 'db:seed' failed", [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
            ]);
            throw $exception;
        }


        $endTime = microtime(true);
        $executionTime = round(($endTime - $startTime) * 1000);

        Log::warning("DB Setup done in {$executionTime}ms (migrate:fresh + db:seed)");

    }

    protected function setTemporaryDefaultConnection(): void
    {

        // Create a new connection without specifying a database
        $tempConnection = Config::get('database.connections.' . env('DB_CONNECTION'));

        Config::set('database.connections.temp', $tempConnection);

        app('db')->setDefaultConnection('temp');

    }


    protected function resetDatabase(): void
    {

        $dbName = getenv("DB_DATABASE_TEST");

        log::debug("Resetting the test database $dbName ...");

        // Drop the database if it exists

        app('db')->unprepared("DROP DATABASE IF EXISTS `{$dbName}`");
        app('db')->unprepared("CREATE DATABASE `{$dbName}`");

        log::info("Dropped/Created database: {$dbName}");

    }

    protected function verifyMigrations()
    {
        // Get all migration files and extract their base names
        $migrationFiles = collect(glob(database_path('migrations') . '/*.php'))
            ->map(function ($path) {
                return basename($path, '.php');
            })
            ->sort();

        // Get the latest migration file
        $latestFileMigration = $migrationFiles->last();

        // Get all applied migrations from the database
        $appliedMigrations = DB::table('migrations')
            ->pluck('migration')
            ->sort();

        // Get the latest applied migration
        $latestAppliedMigration = $appliedMigrations->last();

        // Compare the latest migration file with the latest applied migration
        if ($latestFileMigration === $latestAppliedMigration) {
            Log::info("Latest migration file matches the latest migration record.");
        } else {
            Log::error("Latest migration file does not match the latest migration record.");
            Log::error("Latest file migration: $latestFileMigration");
            Log::error("Latest applied migration: $latestAppliedMigration");
        }
    }

}
