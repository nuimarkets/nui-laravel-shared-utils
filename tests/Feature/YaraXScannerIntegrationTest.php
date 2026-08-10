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

    private function write(string $name, string $content): string
    {
        $path = $this->tmpDir.'/'.$name;
        file_put_contents($path, $content);

        return $path;
    }

    public function test_detects_eicar_with_baseline_rules()
    {
        $path = $this->write('eicar.pdf', $this->eicarContent());

        $this->assertContains('EICAR_Test_File', $this->scanner()->scan([$path])[$path] ?? []);
    }

    public function test_detects_obfuscated_php_eval_with_baseline_rules()
    {
        $path = $this->write('upload.pdf', '<?php eval(base64_decode("cGhwaW5mbygpOw==")); ?>');

        $this->assertContains('PHP_Webshell_Obfuscated_Eval', $this->scanner()->scan([$path])[$path] ?? []);
    }

    public function test_clean_file_returns_no_matches()
    {
        $path = $this->write('clean.txt', 'perfectly ordinary document content');

        $this->assertSame([], $this->scanner()->scan([$path]));
    }

    public function test_batch_attributes_each_match_to_its_own_file()
    {
        $clean = $this->write('clean.txt', 'perfectly ordinary document content');
        $eicar = $this->write('eicar.pdf', $this->eicarContent());
        $shell = $this->write('shell.pdf', '<?php eval(base64_decode("cGhwaW5mbygpOw==")); ?>');

        $matches = $this->scanner()->scan([$clean, $eicar, $shell]);

        $this->assertArrayNotHasKey($clean, $matches);
        $this->assertContains('EICAR_Test_File', $matches[$eicar]);
        $this->assertContains('PHP_Webshell_Obfuscated_Eval', $matches[$shell]);
    }

    public function test_symlinked_source_is_still_scanned()
    {
        // A symlink inside a directory target is skipped by the engine with no
        // error and no match. Staging dereferences it, so a caller handing us
        // one still gets a real verdict rather than a false clean.
        $real = $this->write('eicar.pdf', $this->eicarContent());
        $link = $this->tmpDir.'/link.pdf';
        symlink($real, $link);

        $this->assertContains('EICAR_Test_File', $this->scanner()->scan([$link])[$link] ?? []);
    }

    public function test_engine_still_skips_symlinks_in_a_directory_target()
    {
        // Pins the upstream behavior the staging strategy exists for. If a
        // future YARA-X follows symlinks, this fails and the hard-link
        // requirement can be revisited rather than cargo-culted.
        $dir = $this->tmpDir.'/target';
        mkdir($dir);
        $real = $this->write('eicar-src.pdf', $this->eicarContent());
        symlink($real, $dir.'/linked.pdf');

        $process = new Process([
            $this->binary, 'scan', '--output-format=json', '--disable-warnings',
            '--', YaraXScanner::defaultRulesPath(), $dir,
        ]);
        $process->run();

        try {
            $this->assertSame(0, $process->getExitCode());
            $this->assertSame('', trim($process->getErrorOutput()), 'Engine reported nothing on stderr');
            $this->assertSame([], json_decode($process->getOutput(), true)['matches']);
        } finally {
            @unlink($dir.'/linked.pdf');
            @rmdir($dir);
        }
    }

    public function test_ruleset_that_cannot_catch_the_canary_is_a_scan_failure()
    {
        // The likeliest fail-open in deployment is rules_path pointing at a
        // readable but wrong ruleset: every scan would come back clean. The
        // canary turns that into a loud 503.
        $rules = $this->write('narrow.yar', 'rule Never_Matches { strings: $a = "zzz-not-in-any-test-file-zzz" condition: $a }');
        $path = $this->write('eicar.pdf', $this->eicarContent());

        $scanner = new YaraXScanner($this->binary, $rules);

        $this->expectException(MalwareScanFailedException::class);
        $this->expectExceptionMessage('canary');
        $scanner->scan([$path]);
    }

    public function test_real_binary_with_bad_rules_path_is_scan_failure()
    {
        $path = $this->write('clean.txt', 'content');

        $scanner = new YaraXScanner($this->binary, $this->tmpDir.'/missing-rules');

        $this->expectException(MalwareScanFailedException::class);
        $scanner->scan([$path]);
    }

    public function test_real_binary_with_unreadable_target_is_scan_failure()
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can read anything; permission check not testable');
        }

        $path = $this->write('eicar.pdf', $this->eicarContent());
        chmod($path, 0000);

        try {
            $this->expectException(MalwareScanFailedException::class);
            $this->scanner()->scan([$path]);
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

        $path = $this->write('eicar.pdf', $this->eicarContent());

        $scanner = new YaraXScanner($this->binary, $compiled, compiledRules: true);

        $this->assertContains('EICAR_Test_File', $scanner->scan([$path])[$path] ?? []);
    }
}
