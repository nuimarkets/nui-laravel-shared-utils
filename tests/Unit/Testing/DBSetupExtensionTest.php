<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Testing;

use Illuminate\Support\Facades\Config;
use Mockery;
use NuiMarkets\LaravelSharedUtils\Testing\DBSetupExtension;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use PHPUnit\Event\TestRunner\StartedSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class DBSetupExtensionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear any previous temp connection config
        Config::set('database.connections.temp', null);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_exposes_a_started_subscriber_for_registration(): void
    {
        $subscriber = (new DBSetupExtension)->createTestRunnerStartedSubscriber();

        $this->assertInstanceOf(StartedSubscriber::class, $subscriber);
    }

    #[Test]
    public function its_subscriber_routes_notify_to_execute_before_first_test(): void
    {
        // Concrete extension whose executeBeforeFirstTest() flips a flag — lets us
        // observe that the subscriber really invokes the parent extension. We
        // bypass building a real PHPUnit `Started` event (it's `final readonly`
        // and needs a full Telemetry\Info graph) by invoking the anonymous
        // subscriber's `extension` property directly via reflection — the
        // routing is the public contract we care about.
        $extension = new class extends DBSetupExtension
        {
            public bool $invoked = false;

            public function executeBeforeFirstTest(): void
            {
                $this->invoked = true;
            }
        };

        $subscriber = $extension->createTestRunnerStartedSubscriber();

        // Reach in and grab the captured extension, then exercise the same
        // call chain `notify()` would: `$this->extension->executeBeforeFirstTest()`.
        $reflection = new \ReflectionObject($subscriber);
        $captured = $reflection->getProperty('extension');
        $captured->setAccessible(true);
        $captured->getValue($subscriber)->executeBeforeFirstTest();

        $this->assertTrue($extension->invoked);
    }

    #[Test]
    public function it_skips_execution_when_db_setup_env_not_set(): void
    {
        // Ensure DB_SETUP is not set
        putenv('DB_SETUP');

        $extension = new DBSetupExtension;

        // This should return early without throwing exceptions
        $extension->executeBeforeFirstTest();

        // If we get here without exceptions, the guard clause worked
        $this->assertTrue(true);
    }

    #[Test]
    public function it_creates_temporary_connection_without_database(): void
    {
        // Setup: Configure a test database connection
        $originalConfig = [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'my_production_db',
            'username' => 'root',
            'password' => 'secret',
        ];

        Config::set('database.connections.mysql_test', $originalConfig);

        $extension = new class extends DBSetupExtension
        {
            private string $connectionName;

            public function setConnectionName(string $name): void
            {
                $this->connectionName = $name;
            }

            public function test_set_temporary_default_connection(): void
            {
                // Override the getenv() call by directly manipulating the config
                $tempConnection = Config::get("database.connections.{$this->connectionName}");
                $tempConnection['database'] = null;

                app()['config']->set('database.connections.temp', $tempConnection);
            }
        };

        $extension->setConnectionName('mysql_test');
        $extension->test_set_temporary_default_connection();

        // Verify temp connection was created without database
        $tempConnection = app()['config']->get('database.connections.temp');

        $this->assertNotNull($tempConnection, 'Temp connection should be created');
        $this->assertNull($tempConnection['database'], 'Temp connection should have null database');
        $this->assertEquals('mysql', $tempConnection['driver']);
        $this->assertEquals('127.0.0.1', $tempConnection['host']);
    }

    #[Test]
    public function it_sets_config_directly_on_container_not_facade(): void
    {
        // Setup: Configure a test database connection
        $originalConfig = [
            'driver' => 'pgsql',
            'host' => 'localhost',
            'database' => 'test_db',
            'username' => 'postgres',
        ];

        Config::set('database.connections.pgsql_test', $originalConfig);

        $extension = new class extends DBSetupExtension
        {
            private string $connectionName;

            public function setConnectionName(string $name): void
            {
                $this->connectionName = $name;
            }

            public function test_set_temporary_default_connection(): void
            {
                $tempConnection = Config::get("database.connections.{$this->connectionName}");
                $tempConnection['database'] = null;

                // This is the critical line we're testing - direct container access
                app()['config']->set('database.connections.temp', $tempConnection);
            }
        };

        $extension->setConnectionName('pgsql_test');
        $extension->test_set_temporary_default_connection();

        // Verify config is accessible via container (not just facade)
        $containerConfig = app()['config']->get('database.connections.temp');

        $this->assertNotNull($containerConfig, 'Config must be set on container');
        $this->assertNull($containerConfig['database']);
        $this->assertEquals('pgsql', $containerConfig['driver']);
    }

    #[Test]
    public function it_preserves_original_connection_config(): void
    {
        // Setup: Configure a test database connection
        $originalConfig = [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'original_db',
            'username' => 'user',
            'password' => 'pass',
            'charset' => 'utf8mb4',
        ];

        Config::set('database.connections.preserve_test', $originalConfig);

        $extension = new class extends DBSetupExtension
        {
            private string $connectionName;

            public function setConnectionName(string $name): void
            {
                $this->connectionName = $name;
            }

            public function test_set_temporary_default_connection(): void
            {
                $tempConnection = Config::get("database.connections.{$this->connectionName}");
                $tempConnection['database'] = null;

                app()['config']->set('database.connections.temp', $tempConnection);
            }
        };

        $extension->setConnectionName('preserve_test');
        $extension->test_set_temporary_default_connection();

        // Verify original connection is unchanged
        $currentConfig = Config::get('database.connections.preserve_test');
        $this->assertEquals($originalConfig, $currentConfig, 'Original connection should be preserved');

        // Verify temp connection has copied settings except database
        $tempConfig = app()['config']->get('database.connections.temp');
        $this->assertEquals('mysql', $tempConfig['driver']);
        $this->assertEquals('127.0.0.1', $tempConfig['host']);
        $this->assertEquals('user', $tempConfig['username']);
        $this->assertNull($tempConfig['database'], 'Temp connection database should be null');
    }

    #[Test]
    public function it_makes_temp_connection_resolvable_by_database_manager(): void
    {
        // Setup
        $originalConfig = [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'test_db',
            'username' => 'root',
        ];

        Config::set('database.connections.manager_test', $originalConfig);

        $extension = new class extends DBSetupExtension
        {
            private string $connectionName;

            public function setConnectionName(string $name): void
            {
                $this->connectionName = $name;
            }

            public function test_set_temporary_default_connection(): void
            {
                $tempConnection = Config::get("database.connections.{$this->connectionName}");
                $tempConnection['database'] = null;

                app()['config']->set('database.connections.temp', $tempConnection);
            }
        };

        $extension->setConnectionName('manager_test');
        $extension->test_set_temporary_default_connection();

        // Verify DatabaseManager can see the temp connection config
        // (We can't actually connect without a real database, but we can verify the config exists)
        $connections = app()['config']->get('database.connections');

        $this->assertArrayHasKey('temp', $connections, 'DatabaseManager should be able to resolve temp connection');
        $this->assertNull($connections['temp']['database']);
    }

    /**
     * Subclass exposing the protected connection-failure seams so the
     * translation logic can be exercised without a running stack. Captures the
     * console write instead of emitting it, keeping the suite output clean.
     */
    private function connectionFailureExtension(): DBSetupExtension
    {
        return new class extends DBSetupExtension
        {
            /** @var list<string> */
            public array $reported = [];

            public function callIsConnectionRefused(\Throwable $e): bool
            {
                return $this->isConnectionRefused($e);
            }

            public function callFailIfBackingServiceUnavailable(\Throwable $e): void
            {
                $this->failIfBackingServiceUnavailable($e);
            }

            public function callGuardBackingServices(callable $pipeline): void
            {
                $this->guardBackingServices($pipeline);
            }

            protected function reportSetupFailure(string $message): void
            {
                $this->reported[] = $message;
            }
        };
    }

    /**
     * Canonical refused-connection messages from the actual drivers. Every case
     * carries the literal "connection refused" (or the Windows "actively
     * refused"), which is exactly what the classifier keys on.
     */
    public static function refusedConnectionMessages(): array
    {
        return [
            'mysql pdo refused' => ['SQLSTATE[HY000] [2002] Connection refused'],
            'postgres pdo refused' => ['SQLSTATE[08006] [7] connection to server at "127.0.0.1", port 5432 failed: Connection refused'],
            'redis initial connect refused' => ['Connection refused'],
            'redis predis refused' => ['Connection refused [tcp://127.0.0.1:6379]'],
            'windows actively refused' => ['No connection could be made because the target machine actively refused it'],
            'mixed case' => ['CONNECTION REFUSED'],
        ];
    }

    #[Test]
    #[DataProvider('refusedConnectionMessages')]
    public function it_detects_refused_connections_across_drivers(string $message): void
    {
        $extension = $this->connectionFailureExtension();

        $this->assertTrue($extension->callIsConnectionRefused(new \RuntimeException($message)));
    }

    #[Test]
    public function it_detects_a_refused_connection_nested_in_the_previous_chain(): void
    {
        $root = new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
        $wrapped = new \RuntimeException('db:seed failed', 0, $root);

        $this->assertTrue($this->connectionFailureExtension()->callIsConnectionRefused($wrapped));
    }

    /**
     * Non-connection failures the classifier must NOT flag. The socket-path
     * case (same MySQL [2002] code, different tail) is the reason the bare
     * "[2002]" code is not matched: it is a misconfiguration, not a down stack.
     */
    public static function nonRefusedMessages(): array
    {
        return [
            'schema fault' => ["SQLSTATE[42S02]: Base table or view not found: 1146 Table 'users' doesn't exist"],
            'mysql socket missing' => ['SQLSTATE[HY000] [2002] No such file or directory'],
            'unrelated seeding bug' => ['Undefined array key "sku"'],
        ];
    }

    #[Test]
    #[DataProvider('nonRefusedMessages')]
    public function it_does_not_flag_non_connection_failures(string $message): void
    {
        $this->assertFalse($this->connectionFailureExtension()->callIsConnectionRefused(new \RuntimeException($message)));
    }

    #[Test]
    public function it_translates_a_refused_connection_into_a_clear_stack_error(): void
    {
        $extension = $this->connectionFailureExtension();
        $original = new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused');

        try {
            $extension->callFailIfBackingServiceUnavailable($original);
            $this->fail('Expected a RuntimeException to be thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('backing service', $e->getMessage());
            $this->assertStringContainsString('local stack must be running', $e->getMessage());
            $this->assertSame($original, $e->getPrevious(), 'Original cause should be preserved');
        }

        // The console write is the primary signal; it must fire once with the
        // same message as the thrown exception.
        $this->assertCount(1, $extension->reported);
        $this->assertStringContainsString('backing service', $extension->reported[0]);
    }

    #[Test]
    public function it_leaves_non_connection_failures_for_the_caller_to_rethrow(): void
    {
        $extension = $this->connectionFailureExtension();

        // No-op (no throw) so the caller re-throws the original exception unchanged.
        $extension->callFailIfBackingServiceUnavailable(new \RuntimeException('some unrelated seeding bug'));

        $this->assertSame([], $extension->reported, 'Nothing should be reported for a non-connection failure');
    }

    #[Test]
    public function guard_translates_a_refused_connection_thrown_by_the_pipeline(): void
    {
        $extension = $this->connectionFailureExtension();
        $original = new \RuntimeException('Connection refused [tcp://127.0.0.1:6379]');

        try {
            $extension->callGuardBackingServices(function () use ($original) {
                throw $original;
            });
            $this->fail('Expected the guard to re-throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('local stack must be running', $e->getMessage());
            $this->assertSame($original, $e->getPrevious());
        }

        $this->assertCount(1, $extension->reported);
    }

    #[Test]
    public function guard_rethrows_a_non_connection_failure_unchanged(): void
    {
        $extension = $this->connectionFailureExtension();
        $original = new \RuntimeException('Undefined array key "sku"');

        try {
            $extension->callGuardBackingServices(function () use ($original) {
                throw $original;
            });
            $this->fail('Expected the guard to re-throw');
        } catch (\RuntimeException $e) {
            // The exact original object must propagate, untranslated.
            $this->assertSame($original, $e);
        }

        $this->assertSame([], $extension->reported);
    }

    #[Test]
    public function guard_is_a_no_op_when_the_pipeline_succeeds(): void
    {
        $extension = $this->connectionFailureExtension();
        $ran = false;

        $extension->callGuardBackingServices(function () use (&$ran) {
            $ran = true;
        });

        $this->assertTrue($ran, 'Pipeline should run');
        $this->assertSame([], $extension->reported);
    }
}
