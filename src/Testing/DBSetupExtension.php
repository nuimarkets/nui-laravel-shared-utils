<?php

namespace Nuimarkets\LaravelSharedUtils\Testing;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Nuimarkets\LaravelSharedUtils\Exceptions\BaseErrorHandler;
use Exception;
use PHPUnit\Runner\BeforeFirstTestHook;

/**
 * DB Setup Extension for phpUnit to drop/create testing database with migrations run
 */
class DBSetupExtension implements
    BeforeFirstTestHook
{
    use CreatesApplication;

    /**
     * @throws Exception
     */
    public function executeBeforeFirstTest(): void
    {

        if (getenv("DB_SETUP") !== "1") {
            return;
        }

        $startTime = microtime(true);

        $app = $this->createApplication();

        Log::warning("Running DB Setup (fresh DB + migrations/seeders)");


        $app->bootstrapWith([
            LoadConfiguration::class,
        ]);

        $app->singleton('db.factory', function () use ($app) {
            return new ConnectionFactory($app);
        });

        app()->singleton('db', function () use ($app) {
            return new DatabaseManager($app, $app['db.factory']);
        });

        $app->singleton(
            ExceptionHandler::class,
            BaseErrorHandler::class,
        );

        // Set the application instance for Facades
        Facade::setFacadeApplication($app);

        $this->setTemporaryDefaultConnection();
        $this->resetDatabase();

        app('db')->setDefaultConnection(env('DB_CONNECTION'));

        $testing = Config::get('database.connections.testing');

        Log::debug("Testing connection", [$testing]);

        $this->runMigrations();

        $this->verifyMigrations();

        $this->runSeeder();

        $endTime = microtime(true);
        $executionTime = round(($endTime - $startTime) * 1000);

        Log::warning("DB Setup done in {$executionTime}ms (migrate:fresh + db:seed)");

    }

    protected function runMigrations(): void
    {
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
    }

    protected function runSeeder(): void
    {
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
    }

    protected function setTemporaryDefaultConnection(): void
    {

        // Create a new connection without specifying a database
        $tempConnection = Config::get('database.connections.' . env('DB_CONNECTION'));

        // Temporarily set to null so it won't complain about missing DB :(
        $tempConnection['database'] = null;

        Config::set('database.connections.temp', $tempConnection);

        app('db')->setDefaultConnection('temp');

    }


    protected function resetDatabase(): void
    {

        $dbName = getenv("DB_DATABASE_TEST");

        $connection = app('db')->connection();
        $grammar    = $connection->getQueryGrammar();

        log::debug("Resetting the test database $dbName ...");

        if ($connection->getDriverName() === 'pgsql') {
            Log::debug("Terminating pg connections on $dbName");
            $connection->unprepared("SELECT pg_terminate_backend(pid) FROM pg_stat_activity
                                 WHERE datname = '{$dbName}' AND pid <> pg_backend_pid(); ");
        }

        // wrap() to use backticks on MySQL, double-quotes on Postgres, etc.
        $quotedName = $grammar->wrap($dbName);

        // Drop the database if it exists

        $connection->unprepared("DROP DATABASE IF EXISTS {$quotedName}");
        $connection->unprepared("CREATE DATABASE {$quotedName}");

        log::info("Dropped/Created database: {$dbName}");

    }

    protected function verifyMigrations(): void
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
