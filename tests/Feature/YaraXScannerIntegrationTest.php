<?php

namespace NuiMarkets\LaravelSharedUtils\Tests\Feature;

use NuiMarkets\LaravelSharedUtils\Exceptions\MalwareScanFailedException;
use NuiMarkets\LaravelSharedUtils\Services\YaraXScanner;
use NuiMarkets\LaravelSharedUtils\Tests\TestCase;
use Symfony\Component\Process\Process;

/**
 * Opt-in integration test against a real YARA-X binary and the baseline
 * ruleset shipped in resources/malware-rules. Skipped unless the binary is
 * available: set YARA_X_BINARY=/path/to/yr or have `yr` on PATH.
 */
class YaraXScannerIntegrationTest extends TestCase
{
    private string $binary;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $binary = $this->locateBinary();
        if ($binary === null) {
            $this->markTestSkipped('YARA-X binary not available; set YARA_X_BINARY or put `yr` on PATH');
        }
        $this->binary = $binary;

        $this->tmpDir = sys_get_temp_dir().'/yara_integration_test_'.uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        if (isset($this->tmpDir)) {
            foreach (glob($this->tmpDir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function locateBinary(): ?string
    {
        $env = getenv('YARA_X_BINARY');
        if (is_string($env) && $env !== '' && is_executable($env)) {
            return $env;
        }

        $which = trim((string) shell_exec('command -v yr 2>/dev/null'));

        return $which !== '' ? $which : null;
    }

    private function scanner(): YaraXScanner
    {
        return new YaraXScanner($this->binary, YaraXScanner::defaultRulesPath());
    }

    /**
     * The EICAR test string, assembled at runtime so the repo checkout itself
     * doesn't trip antivirus scanners.
     */
    private function eicarContent(): string
    {
        return 'X5O!P%@AP[4\PZX54(P^)7CC)7}$'.'EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
    }

    public function test_detects_eicar_with_baseline_rules()
    {
        $path = $this->tmpDir.'/eicar.pdf';
        file_put_contents($path, $this->eicarContent());

        $matches = $this->scanner()->scan($path);

        $this->assertContains('EICAR_Test_File', $matches);
    }

    public function test_detects_obfuscated_php_eval_with_baseline_rules()
    {
        $path = $this->tmpDir.'/upload.pdf';
        file_put_contents($path, '<?php eval(base64_decode("cGhwaW5mbygpOw==")); ?>');

        $matches = $this->scanner()->scan($path);

        $this->assertContains('PHP_Webshell_Obfuscated_Eval', $matches);
    }

    public function test_clean_file_returns_no_matches()
    {
        $path = $this->tmpDir.'/clean.txt';
        file_put_contents($path, 'perfectly ordinary document content');

        $this->assertSame([], $this->scanner()->scan($path));
    }

    public function test_real_binary_with_bad_rules_path_is_scan_failure()
    {
        $path = $this->tmpDir.'/clean.txt';
        file_put_contents($path, 'content');

        $scanner = new YaraXScanner($this->binary, $this->tmpDir.'/missing-rules');

        $this->expectException(MalwareScanFailedException::class);
        $scanner->scan($path);
    }

    public function test_real_binary_with_unreadable_target_is_scan_failure()
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can read anything; permission check not testable');
        }

        $path = $this->tmpDir.'/eicar.pdf';
        file_put_contents($path, $this->eicarContent());
        chmod($path, 0000);

        try {
            $this->expectException(MalwareScanFailedException::class);
            $this->scanner()->scan($path);
        } finally {
            chmod($path, 0644);
        }
    }

    public function test_compiled_baseline_rules_detect_eicar()
    {
        $compiled = $this->tmpDir.'/baseline.yarc';
        $compile = new Process([
            $this->binary, 'compile', '--output', $compiled, YaraXScanner::defaultRulesPath(),
        ]);
        $compile->mustRun();

        $path = $this->tmpDir.'/eicar.pdf';
        file_put_contents($path, $this->eicarContent());

        $scanner = new YaraXScanner($this->binary, $compiled, compiledRules: true);

        $this->assertContains('EICAR_Test_File', $scanner->scan($path));
    }
}
