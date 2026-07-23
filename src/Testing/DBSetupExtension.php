<?php

namespace NuiMarkets\LaravelSharedUtils\Testing;

use Exception;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use NuiMarkets\LaravelSharedUtils\Exceptions\BaseErrorHandler;
use PHPUnit\Event\TestRunner\Started;
use PHPUnit\Event\TestRunner\StartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade as PHPUnitExtensionFacade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use RuntimeException;
use Throwable;

/**
 * DB Setup Extension for PHPUnit to drop/create testing database with migrations run.
 *
 * Register in phpunit.xml:
 *   <extensions>
 *     <bootstrap class="NuiMarkets\LaravelSharedUtils\Testing\DBSetupExtension"/>
 *   </extensions>
 */
class DBSetupExtension implements Extension
{
    use CreatesApplication;

    public function bootstrap(Configuration $configuration, PHPUnitExtensionFacade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber($this->createTestRunnerStartedSubscriber());
    }

    /**
     * Build the subscriber that runs `executeBeforeFirstTest()` when PHPUnit
     * fires its `TestRunner\Started` event. Exposed for unit testing because
     * `PHPUnit\Runner\Extension\Facade` is final and cannot be mocked.
     *
     * @internal
     */
    public function createTestRunnerStartedSubscriber(): StartedSubscriber
    {
        return new class($this) implements StartedSubscriber
        {
            public function __construct(private DBSetupExtension $extension) {}

            public function notify(Started $event): void
            {
                $this->extension->executeBeforeFirstTest();
            }
        };
    }

    /**
     * @throws Exception
     */
    public function executeBeforeFirstTest(): void
    {

        if (getenv('DB_SETUP') !== '1') {
            return;
        }

        $startTime = microtime(true);

        $app = $this->createApplication();

        Log::warning('Running DB Setup (fresh DB + migrations/seeders)');

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

        // A refused connection during setup almost always means a local
        // backing service is down, not a schema/data fault. The guard
        // translates that into a clear, early error and re-throws anything
        // else unchanged.
        $this->guardBackingServices(function () {
            $this->setTemporaryDefaultConnection();
            $this->resetDatabase();

            app('db')->setDefaultConnection(env('DB_CONNECTION'));

            $testing = Config::get('database.connections.testing');

            Log::debug('Testing connection', [$testing]);

            $this->runMigrations();

            $this->verifyMigrations();

            $this->runSeeder();
        });

        $endTime = microtime(true);
        $executionTime = round(($endTime - $startTime) * 1000);

        Log::warning("DB Setup done in {$executionTime}ms (migrate:fresh + db:seed)");

    }

    /**
     * Run the DB-setup pipeline, translating a refused-connection failure into
     * a clear, early error. Any other failure is re-thrown unchanged so real
     * schema/data faults surface as themselves. Kept as its own method so the
     * catch/translate/re-throw boundary is unit-testable without a bootstrapped
     * application.
     */
    protected function guardBackingServices(callable $pipeline): void
    {
        try {
            $pipeline();
        } catch (Throwable $e) {
            $this->failIfBackingServiceUnavailable($e);

            throw $e;
        }
    }

    /**
     * Translate a refused-connection failure during DB setup into a clear,
     * early error naming the likely cause: a local backing service the test
     * suite depends on (database and/or cache) is not running.
     *
     * Seeding can touch more than the database. A model event fired while
     * seeding (for example one that flushes a response/redis cache on save)
     * can reach a cache backend, so a refused connection here is usually "the
     * local stack is down", not a schema or data problem. Left untranslated,
     * the original failure surfaces as a buried driver trace, often behind an
     * earlier SQL "Connection refused", which reads as a database fault and
     * misdirects debugging.
     *
     * No-op when the failure is not a connection error; the caller then
     * re-throws the original exception unchanged.
     */
    protected function failIfBackingServiceUnavailable(Throwable $e): void
    {
        if (! $this->isConnectionRefused($e)) {
            return;
        }

        $message = 'Test DB setup could not reach a required backing service '
            .'(database and/or cache). The local stack must be running before '
            .'the test database can be built and seeded: start it and re-run. '
            .'Original error: '.$e->getMessage();

        $this->reportSetupFailure($message);

        throw new RuntimeException($message, 0, $e);
    }

    /**
     * Emit the setup-failure message straight to the console.
     *
     * This is the primary signal, not a nicety: the RuntimeException thrown by
     * failIfBackingServiceUnavailable() bubbles through a PHPUnit event
     * subscriber, where PHPUnit classifies a throwable originating outside its
     * own source as a third-party-subscriber error, downgrades it to a warning,
     * and does NOT re-throw (the run continues). Without this write the cause
     * would be buried behind the earlier driver error. Overridable so tests can
     * capture it instead of writing to the console.
     */
    protected function reportSetupFailure(string $message): void
    {
        fwrite(STDERR, PHP_EOL.'[DBSetupExtension] '.$message.PHP_EOL.PHP_EOL);
    }

    /**
     * True when the throwable (or anything in its previous chain) looks like a
     * refused TCP connection to a backing service: MySQL/Postgres via PDO, or
     * Redis via predis/phpredis. Matches on message text so it stays
     * client-agnostic across drivers.
     *
     * Scoped deliberately to "connection refused" (the observed failure mode
     * for a down local stack). The canonical refused messages already carry
     * that phrase, so matching the bare MySQL "[2002]" code is unnecessary and
     * would mis-flag "[2002] No such file or directory" (a socket-path
     * misconfiguration) as a stopped service.
     */
    protected function isConnectionRefused(Throwable $e): bool
    {
        $pattern = '/connection refused|actively refused/i';

        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (preg_match($pattern, $current->getMessage()) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function runMigrations(): void
    {
        try {
            Log::info('Running migrate:fresh');
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

    public function runTestingMigrations(): void
    {
        $testingMigrationsPath = database_path('migrations/testing');

        if (! is_dir($testingMigrationsPath)) {
            Log::info('No testing migrations directory found, skipping testing migrations');

            return;
        }

        try {
            Log::info('Running testing migrate');
            Artisan::call('migrate', [
                '--path' => str_replace(base_path().'/', '', $testingMigrationsPath),
            ]);
        } catch (Exception $exception) {
            Log::error("Running testing 'migrate' failed", [
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
            Log::info('Running db:seed');
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

    public function runTestingSeeder(?string $seederClass = null): void
    {
        if (! $seederClass) {
            Log::info('No testing seeder, skipping testing seeder run');

            return;
        }

        try {
            Log::info("Attempting to run testing Seeder: {$seederClass}");
            Artisan::call('db:seed', [
                '--class' => $seederClass,
            ]);
        } catch (Exception $exception) {
            // Log the error but don't throw - it's optional if the seeder doesn't exist
            Log::warning("{$seederClass} not found or failed to run", [
                'message' => $exception->getMessage(),
            ]);
        }
    }

    protected function setTemporaryDefaultConnection(): void
    {
        // Create a new connection without specifying a database
        $tempConnection = Config::get('database.connections.'.env('DB_CONNECTION'));

        // Temporarily set to null so it won't complain about missing DB :(
        $tempConnection['database'] = null;

        // Set directly on the container's config repository to ensure DatabaseManager
        // can resolve the temporary connection. During test bootstrapping, setting via
        // the container is more reliable than using the Config facade.
        app()['config']->set('database.connections.temp', $tempConnection);

        app('db')->setDefaultConnection('temp');
    }

    protected function resetDatabase(): void
    {

        $dbName = getenv('DB_DATABASE_TEST');

        $connection = app('db')->connection();
        $grammar = $connection->getQueryGrammar();

        Log::debug("Resetting the test database $dbName ...");

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

        Log::info("Dropped/Created database: {$dbName}");

    }

    protected function verifyMigrations(): void
    {
        // Get all migration files and extract their base names
        $migrationFiles = collect(glob(database_path('migrations').'/*.php'))
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
            Log::info('Latest migration file matches the latest migration record.');
        } else {
            Log::error('Latest migration file does not match the latest migration record.');
            Log::error("Latest file migration: $latestFileMigration");
            Log::error("Latest applied migration: $latestAppliedMigration");
        }
    }
}
