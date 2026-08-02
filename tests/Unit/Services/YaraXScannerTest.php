<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Unit\Services;

use NuiMarkets\LaravelSharedUtils\Exceptions\MalwareScanFailedException;
use NuiMarkets\LaravelSharedUtils\Services\YaraXScanner;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;

/**
 * Unit tests for YaraXScanner driven by stub executables (small shell scripts
 * standing in for the yr binary), so the suite never needs YARA-X installed.
 * The real-binary contract is covered by the opt-in
 * Feature\YaraXScannerIntegrationTest.
 */
class YaraXScannerTest extends TestCase
{
    private string $workDir;

    private string $targetFile;

    private string $rulesFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().'/yara_scanner_test_'.uniqid();
        mkdir($this->workDir);

        $this->targetFile = $this->workDir.'/target.txt';
        file_put_contents($this->targetFile, 'scan me');

        $this->rulesFile = $this->workDir.'/rules.yar';
        file_put_contents($this->rulesFile, '// stub rules, never parsed by stub binaries');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/*') ?: [] as $f) {
            @chmod($f, 0644);
            @unlink($f);
        }
        @rmdir($this->workDir);
        parent::tearDown();
    }

    /**
     * Write an executable shell script standing in for the yr binary.
     */
    private function stubBinary(string $body): string
    {
        $path = $this->workDir.'/yr_stub_'.uniqid();
        file_put_contents($path, "#!/bin/sh\n{$body}\n");
        chmod($path, 0755);

        return $path;
    }

    private function scanner(string $binary, ?string $rulesPath = null, bool $compiledRules = false, int $timeoutSeconds = 10): YaraXScanner
    {
        return new YaraXScanner($binary, $rulesPath ?? $this->rulesFile, $compiledRules, $timeoutSeconds);
    }

    public function test_clean_scan_returns_empty_list()
    {
        $binary = $this->stubBinary('echo \'{"version":"1.19.0","matches":[]}\'');

        $this->assertSame([], $this->scanner($binary)->scan($this->targetFile));
    }

    public function test_match_returns_deduplicated_rule_identifiers()
    {
        $binary = $this->stubBinary(
            'echo \'{"version":"1.19.0","matches":[{"rule":"Rule_A","file":"/tmp/x"},{"rule":"Rule_B","file":"/tmp/x"},{"rule":"Rule_A","file":"/tmp/x"}]}\''
        );

        $this->assertSame(['Rule_A', 'Rule_B'], $this->scanner($binary)->scan($this->targetFile));
    }

    public function test_stderr_with_exit_zero_is_scan_failure()
    {
        // yr's most dangerous behavior: an unreadable TARGET file exits 0 with
        // empty matches and the error on stderr only. Trusting stdout alone
        // would silently fail open.
        $binary = $this->stubBinary(
            'echo \'{"version":"1.19.0","matches":[]}\'; echo \'error: can not open target\' >&2'
        );

        $this->expectException(MalwareScanFailedException::class);
        $this->scanner($binary)->scan($this->targetFile);
    }

    public function test_nonzero_exit_is_scan_failure()
    {
        $binary = $this->stubBinary('echo \'error: bad rules\' >&2; exit 1');

        $this->expectException(MalwareScanFailedException::class);
        $this->scanner($binary)->scan($this->targetFile);
    }

    public function test_invalid_json_output_is_scan_failure()
    {
        $binary = $this->stubBinary('echo \'not json at all\'');

        $this->expectException(MalwareScanFailedException::class);
        $this->scanner($binary)->scan($this->targetFile);
    }

    public function test_missing_matches_key_is_scan_failure()
    {
        $binary = $this->stubBinary('echo \'{"version":"1.19.0"}\'');

        $this->expectException(MalwareScanFailedException::class);
        $this->scanner($binary)->scan($this->targetFile);
    }

    public function test_non_array_matches_is_scan_failure()
    {
        $binary = $this->stubBinary('echo \'{"version":"1.19.0","matches":"clean"}\'');

        $this->expectException(MalwareScanFailedException::class);
        $this->scanner($binary)->scan($this->targetFile);
    }

    public function test_match_entry_without_rule_is_scan_failure()
    {
        $binary = $this->stubBinary('echo \'{"version":"1.19.0","matches":[{"file":"/tmp/x"}]}\'');

        $this->expectException(MalwareScanFailedException::class);
        $this->scanner($binary)->scan($this->targetFile);
    }

    public function test_timeout_is_scan_failure()
    {
        $binary = $this->stubBinary('sleep 3; echo \'{"version":"1.19.0","matches":[]}\'');

        $this->expectException(MalwareScanFailedException::class);
        $this->expectExceptionMessage('timed out');
        $this->scanner($binary, timeoutSeconds: 1)->scan($this->targetFile);
    }

    public function test_missing_binary_is_scan_failure()
    {
        $this->expectException(MalwareScanFailedException::class);
        $this->scanner($this->workDir.'/does_not_exist')->scan($this->targetFile);
    }

    public function test_missing_target_file_is_scan_failure()
    {
        $binary = $this->stubBinary('echo \'{"version":"1.19.0","matches":[]}\'');

        $this->expectException(MalwareScanFailedException::class);
        $this->expectExceptionMessage('target is not readable');
        $this->scanner($binary)->scan($this->workDir.'/missing.txt');
    }

    public function test_unreadable_target_file_is_scan_failure()
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can read anything; permission check not testable');
        }

        $binary = $this->stubBinary('echo \'{"version":"1.19.0","matches":[]}\'');
        chmod($this->targetFile, 0000);

        $this->expectException(MalwareScanFailedException::class);
        $this->expectExceptionMessage('target is not readable');
        $this->scanner($binary)->scan($this->targetFile);
    }

    public function test_missing_rules_path_is_scan_failure()
    {
        $binary = $this->stubBinary('echo \'{"version":"1.19.0","matches":[]}\'');

        $this->expectException(MalwareScanFailedException::class);
        $this->expectExceptionMessage('rules path is not readable');
        $this->scanner($binary, rulesPath: $this->workDir.'/missing-rules.yar')->scan($this->targetFile);
    }

    public function test_invokes_binary_with_expected_arguments()
    {
        $argsFile = $this->workDir.'/args.txt';
        $binary = $this->stubBinary(
            'printf \'%s\n\' "$@" > '.escapeshellarg($argsFile).'; echo \'{"version":"1.19.0","matches":[]}\''
        );

        $this->scanner($binary)->scan($this->targetFile);

        $args = file($argsFile, FILE_IGNORE_NEW_LINES);
        $this->assertSame([
            'scan',
            '--output-format=json',
            '--disable-warnings',
            '--',
            $this->rulesFile,
            $this->targetFile,
        ], $args);
    }

    public function test_compiled_rules_flag_added_when_configured()
    {
        $argsFile = $this->workDir.'/args.txt';
        $binary = $this->stubBinary(
            'printf \'%s\n\' "$@" > '.escapeshellarg($argsFile).'; echo \'{"version":"1.19.0","matches":[]}\''
        );

        $this->scanner($binary, compiledRules: true)->scan($this->targetFile);

        $args = file($argsFile, FILE_IGNORE_NEW_LINES);
        $this->assertContains('--compiled-rules', $args);
    }

    public function test_scan_failure_extra_caps_captured_stderr()
    {
        $binary = $this->stubBinary('head -c 5000 /dev/zero | tr \'\0\' \'e\' >&2; exit 1');

        try {
            $this->scanner($binary)->scan($this->targetFile);
            $this->fail('Expected MalwareScanFailedException');
        } catch (MalwareScanFailedException $e) {
            $this->assertLessThanOrEqual(1000, strlen($e->getExtra()['stderr']));
        }
    }

    public function test_from_config_uses_defaults()
    {
        $scanner = YaraXScanner::fromConfig([]);

        $reflection = new \ReflectionClass($scanner);
        $this->assertSame('yr', $reflection->getProperty('binary')->getValue($scanner));
        $this->assertSame(YaraXScanner::defaultRulesPath(), $reflection->getProperty('rulesPath')->getValue($scanner));
        $this->assertFalse($reflection->getProperty('compiledRules')->getValue($scanner));
        $this->assertSame(10, $reflection->getProperty('timeoutSeconds')->getValue($scanner));
    }

    public function test_from_config_respects_overrides()
    {
        $scanner = YaraXScanner::fromConfig([
            'binary' => '/usr/local/bin/yr',
            'rules_path' => '/etc/yara/rules.yarc',
            'compiled_rules' => true,
            'timeout_seconds' => 30,
        ]);

        $reflection = new \ReflectionClass($scanner);
        $this->assertSame('/usr/local/bin/yr', $reflection->getProperty('binary')->getValue($scanner));
        $this->assertSame('/etc/yara/rules.yarc', $reflection->getProperty('rulesPath')->getValue($scanner));
        $this->assertTrue($reflection->getProperty('compiledRules')->getValue($scanner));
        $this->assertSame(30, $reflection->getProperty('timeoutSeconds')->getValue($scanner));
    }

    public function test_from_config_ignores_compiled_rules_for_default_rules_path()
    {
        // The baseline ruleset is .yar source; a stray compiled_rules=true
        // must not apply to it (it would fail every scan, closed).
        $scanner = YaraXScanner::fromConfig(['compiled_rules' => true]);

        $reflection = new \ReflectionClass($scanner);
        $this->assertFalse($reflection->getProperty('compiledRules')->getValue($scanner));
        $this->assertSame(YaraXScanner::defaultRulesPath(), $reflection->getProperty('rulesPath')->getValue($scanner));
    }

    public function test_from_config_treats_non_positive_timeout_as_default()
    {
        foreach ([0, -5, ''] as $bad) {
            $scanner = YaraXScanner::fromConfig(['timeout_seconds' => $bad]);

            $reflection = new \ReflectionClass($scanner);
            $this->assertSame(10, $reflection->getProperty('timeoutSeconds')->getValue($scanner));
        }
    }

    public function test_default_rules_path_ships_with_package()
    {
        $this->assertDirectoryExists(YaraXScanner::defaultRulesPath());
        $this->assertNotEmpty(glob(YaraXScanner::defaultRulesPath().'/*.yar'));
    }
}
