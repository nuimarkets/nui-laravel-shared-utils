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
 *
 * The scanner stages a batch into a private directory and hands the engine that
 * one directory, so a stub sees staged names (`f0`, `f1`, ...) plus the canary,
 * never the caller's paths.
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

    /**
     * A stub that prints a well-formed match list. Matches are reported against
     * the staging directory the scanner actually passed it (its last argument),
     * because the scanner attributes by exact path.
     *
     * The canary match is included by default, since a batch without it is a
     * scan failure by design; pass false to simulate an engine that scanned
     * nothing. Pass a name starting with '/' to report an absolute path outside
     * the staging directory.
     *
     * @param  list<array{0: string, 1: string}>  $matches  [rule, staged name] pairs
     */
    private function matchingStub(array $matches = [], bool $withCanary = true): string
    {
        $entries = $withCanary
            ? array_merge([['Canary_Rule', YaraXScanner::CANARY_NAME]], $matches)
            : $matches;

        $parts = [];
        $args = [];

        foreach ($entries as [$rule, $name]) {
            if (str_starts_with($name, '/')) {
                $parts[] = sprintf('{"rule":"%s","file":"%s"}', $rule, $name);

                continue;
            }

            $parts[] = sprintf('{"rule":"%s","file":"%%s/%s"}', $rule, $name);
            $args[] = '"$d"';
        }

        $format = '{"version":"1.19.0","matches":['.implode(',', $parts).']}\n';

        return $this->stubBinary(
            'for a in "$@"; do d="$a"; done; '
            .'printf '.escapeshellarg($format).($args === [] ? '' : ' '.implode(' ', $args))
        );
    }

    /**
     * Shell fragment printing a canary-only match list, for stubs that need to
     * do something else first. Assumes `$d` holds the staging directory.
     */
    private function printCanaryJson(): string
    {
        return 'printf '.escapeshellarg(
            '{"version":"1.19.0","matches":[{"rule":"Canary_Rule","file":"%s/'.YaraXScanner::CANARY_NAME.'"}]}\n'
        ).' "$d"';
    }

    private function scanner(string $binary, ?string $rulesPath = null, bool $compiledRules = false, int $timeoutSeconds = 10): YaraXScanner
    {
        return new YaraXScanner($binary, $rulesPath ?? $this->rulesFile, $compiledRules, $timeoutSeconds);
    }

    public function test_empty_batch_returns_empty_and_never_runs_the_engine()
    {
        $marker = $this->workDir.'/ran.txt';
        $binary = $this->stubBinary('touch '.escapeshellarg($marker));

        $this->assertSame([], $this->scanner($binary)->scan([]));
        $this->assertFileDoesNotExist($marker);
    }

    public function test_clean_batch_returns_no_matched_paths()
    {
        $this->assertSame([], $this->scanner($this->matchingStub())->scan([$this->targetFile]));
    }

    public function test_matches_map_back_to_the_caller_paths()
    {
        $second = $this->workDir.'/second.txt';
        file_put_contents($second, 'also scan me');

        $matches = $this->scanner($this->matchingStub([['Rule_B', 'f1']]))
            ->scan([$this->targetFile, $second]);

        // f1 is the second path staged, so only that one comes back matched.
        $this->assertSame([$second => ['Rule_B']], $matches);
    }

    public function test_match_returns_deduplicated_rule_identifiers()
    {
        $stub = $this->matchingStub([['Rule_A', 'f0'], ['Rule_B', 'f0'], ['Rule_A', 'f0']]);

        $this->assertSame(
            [$this->targetFile => ['Rule_A', 'Rule_B']],
            $this->scanner($stub)->scan([$this->targetFile]),
        );
    }

    public function test_unmatched_canary_is_scan_failure()
    {
        // No canary match means the engine scanned nothing, or the configured
        // ruleset is not the one the image was built with. Either way an empty
        // match list is not evidence the files are clean.
        $stub = $this->matchingStub(withCanary: false);

        $this->expectException(MalwareScanFailedException::class);
        $this->expectExceptionMessage('canary');
        $this->scanner($stub)->scan([$this->targetFile]);
    }

    public function test_match_on_a_file_that_was_never_staged_is_scan_failure()
    {
        $stub = $this->matchingStub([['Rule_A', 'f7']]);

        $this->expectException(MalwareScanFailedException::class);
        $this->expectExceptionMessage('not given');
        $this->scanner($stub)->scan([$this->targetFile]);
    }

    public function test_canary_named_file_outside_the_staging_directory_does_not_satisfy_the_control()
    {
        // The canary's authority comes from being the file this invocation
        // planted in this directory. A same-named file reported from anywhere
        // else must not stand in for it, or the positive control is defeated by
        // a filename.
        $stub = $this->matchingStub(
            [['Impostor_Rule', '/elsewhere/'.YaraXScanner::CANARY_NAME]],
            withCanary: false,
        );

        $this->expectException(MalwareScanFailedException::class);
        $this->expectExceptionMessage('canary');
        $this->scanner($stub)->scan([$this->targetFile]);
    }

    public function test_staging_directory_is_private()
    {
        $modeFile = $this->workDir.'/mode.txt';
        $binary = $this->stubBinary(
            'for a in "$@"; do d="$a"; done; '
            .'stat -c %a "$d" > '.escapeshellarg($modeFile).'; '
            .$this->printCanaryJson()
        );

        $this->scanner($binary)->scan([$this->targetFile]);

        // Uploads and a live EICAR sample sit here; nothing else on the box
        // needs to read them.
        $this->assertSame('700', trim(file_get_contents($modeFile)));
    }

    public function test_batch_is_staged_as_real_files_never_symlinks()
    {
        // A symlink inside a directory target is skipped by the engine with no
        // error and no match, which would read back as clean. Pin that staging
        // never produces one, and that the canary is really there.
        $manifest = $this->workDir.'/manifest.txt';
        $binary = $this->stubBinary(
            'for a in "$@"; do d="$a"; done; '
            .'for f in "$d"/*; do '
            .'if [ -L "$f" ]; then t=link; elif [ -f "$f" ]; then t=file; else t=other; fi; '
            .'echo "$(basename "$f") $t $(wc -c < "$f")" >> '.escapeshellarg($manifest).'; '
            .'done; '
            .$this->printCanaryJson()
        );

        $second = $this->workDir.'/second.txt';
        file_put_contents($second, 'a longer piece of content');

        $this->scanner($binary)->scan([$this->targetFile, $second]);

        $lines = file($manifest, FILE_IGNORE_NEW_LINES);
        sort($lines);

        $this->assertSame([
            'f0 file '.filesize($this->targetFile),
            'f1 file '.filesize($second),
            YaraXScanner::CANARY_NAME.' file '.strlen(YaraXScanner::canaryContent()),
        ], $lines);
    }

    public function test_symlinked_source_is_staged_as_a_real_file()
    {
        // The caller may hand us a symlink; staging must dereference it rather
        // than carry the symlink into the batch, where it would be skipped.
        $link = $this->workDir.'/link.txt';
        symlink($this->targetFile, $link);

        $manifest = $this->workDir.'/manifest.txt';
        $binary = $this->stubBinary(
            'for a in "$@"; do d="$a"; done; '
            .'if [ -L "$d/f0" ]; then echo link > '.escapeshellarg($manifest).'; '
            .'else echo "file $(wc -c < "$d/f0")" > '.escapeshellarg($manifest).'; fi; '
            .$this->printCanaryJson()
        );

        $this->scanner($binary)->scan([$link]);

        $this->assertSame('file '.filesize($this->targetFile), trim(file_get_contents($manifest)));
    }

    public function test_staging_directory_is_removed_after_a_scan()
    {
        $dirFile = $this->workDir.'/dir.txt';
        $binary = $this->stubBinary(
            'for a in "$@"; do d="$a"; done; echo "$d" > '.escapeshellarg($dirFile).'; '
            .$this->printCanaryJson()
        );

        $this->scanner($binary)->scan([$this->targetFile]);

        $this->assertDirectoryDoesNotExist(trim(file_get_contents($dirFile)));
    }

    public function test_staging_directory_is_removed_after_a_failed_scan()
    {
        $dirFile = $this->workDir.'/dir.txt';
        $binary = $this->stubBinary(
            'for a in "$@"; do d="$a"; done; echo "$d" > '.escapeshellarg($dirFile).'; '
            .'echo \'boom\' >&2; exit 1'
        );

        try {
            $this->scanner($binary)->scan([$this->targetFile]);
            $this->fail('Expected MalwareScanFailedException');
        } catch (MalwareScanFailedException $e) {
            $this->assertDirectoryDoesNotExist(trim(file_get_contents($dirFile)));
        }
    }

    public function test_stderr_with_exit_zero_is_scan_failure()
    {
        // yr's most dangerous behavior: an unreadable target exits 0 with empty
        // matches and the error on stderr only. Trusting stdout alone would
        // silently fail open.
        $binary = $this->stubBinary(
            'echo \'{"version":"1.19.0","matches":[]}\'; echo \'error: can not open target\' >&2'
        );

        $this->expectException(MalwareScanFailedException::class);
        $this->scanner($binary)->scan([$this->targetFile]);
    }

    public function test_nonzero_exit_is_scan_failure()
    {
        $binary = $this->stubBinary('echo \'error: bad rules\' >&2; exit 1');

        $this->expectException(MalwareScanFailedException::class);
        $this->scanner($binary)->scan([$this->targetFile]);
    }

    public function test_invalid_json_output_is_scan_failure()
    {
        $binary = $this->stubBinary('echo \'not json at all\'');

        $this->expectException(MalwareScanFailedException::class);
        $this->scanner($binary)->scan([$this->targetFile]);
    }

    public function test_missing_matches_key_is_scan_failure()
    {
        $binary = $this->stubBinary('echo \'{"version":"1.19.0"}\'');

        $this->expectException(MalwareScanFailedException::class);
        $this->scanner($binary)->scan([$this->targetFile]);
    }

    public function test_non_array_matches_is_scan_failure()
    {
        $binary = $this->stubBinary('echo \'{"version":"1.19.0","matches":"clean"}\'');

        $this->expectException(MalwareScanFailedException::class);
        $this->scanner($binary)->scan([$this->targetFile]);
    }

    public function test_match_entry_without_rule_is_scan_failure()
    {
        $binary = $this->stubBinary('echo \'{"version":"1.19.0","matches":[{"file":"/staged/f0"}]}\'');

        $this->expectException(MalwareScanFailedException::class);
        $this->scanner($binary)->scan([$this->targetFile]);
    }

    public function test_match_entry_without_file_is_scan_failure()
    {
        // Without a file there is no way to attribute the match, and guessing
        // would either free a dirty file or condemn a clean one.
        $binary = $this->stubBinary('echo \'{"version":"1.19.0","matches":[{"rule":"Rule_A"}]}\'');

        $this->expectException(MalwareScanFailedException::class);
        $this->scanner($binary)->scan([$this->targetFile]);
    }

    public function test_timeout_is_scan_failure()
    {
        $binary = $this->stubBinary('sleep 3; echo \'{"version":"1.19.0","matches":[]}\'');

        $this->expectException(MalwareScanFailedException::class);
        $this->expectExceptionMessage('timed out');
        $this->scanner($binary, timeoutSeconds: 1)->scan([$this->targetFile]);
    }

    public function test_missing_binary_is_scan_failure()
    {
        $this->expectException(MalwareScanFailedException::class);
        $this->scanner($this->workDir.'/does_not_exist')->scan([$this->targetFile]);
    }

    public function test_missing_target_file_is_scan_failure()
    {
        $binary = $this->matchingStub();

        $this->expectException(MalwareScanFailedException::class);
        $this->expectExceptionMessage('target is not readable');
        $this->scanner($binary)->scan([$this->workDir.'/missing.txt']);
    }

    public function test_one_unreadable_file_fails_the_whole_batch()
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root can read anything; permission check not testable');
        }

        $bad = $this->workDir.'/unreadable.txt';
        file_put_contents($bad, 'secret');
        chmod($bad, 0000);

        $this->expectException(MalwareScanFailedException::class);
        $this->expectExceptionMessage('target is not readable');
        $this->scanner($this->matchingStub())->scan([$this->targetFile, $bad]);
    }

    public function test_missing_rules_path_is_scan_failure()
    {
        $binary = $this->matchingStub();

        $this->expectException(MalwareScanFailedException::class);
        $this->expectExceptionMessage('rules path is not readable');
        $this->scanner($binary, rulesPath: $this->workDir.'/missing-rules.yar')->scan([$this->targetFile]);
    }

    public function test_invokes_binary_with_expected_arguments()
    {
        $argsFile = $this->workDir.'/args.txt';
        $binary = $this->stubBinary(
            'for a in "$@"; do d="$a"; done; '
            .'printf \'%s\n\' "$@" > '.escapeshellarg($argsFile).'; '
            .$this->printCanaryJson()
        );

        $this->scanner($binary)->scan([$this->targetFile]);

        $args = file($argsFile, FILE_IGNORE_NEW_LINES);
        $target = array_pop($args);

        $this->assertSame([
            'scan',
            '--output-format=json',
            '--disable-warnings',
            '--',
            $this->rulesFile,
        ], $args);

        // One target, and it is the staging directory rather than any caller path.
        $this->assertStringContainsString('/malware-scan-', $target);
        $this->assertNotSame($this->targetFile, $target);
    }

    public function test_compiled_rules_flag_added_when_configured()
    {
        $argsFile = $this->workDir.'/args.txt';
        $binary = $this->stubBinary(
            'for a in "$@"; do d="$a"; done; '
            .'printf \'%s\n\' "$@" > '.escapeshellarg($argsFile).'; '
            .$this->printCanaryJson()
        );

        $this->scanner($binary, compiledRules: true)->scan([$this->targetFile]);

        $args = file($argsFile, FILE_IGNORE_NEW_LINES);
        $this->assertContains('--compiled-rules', $args);
    }

    public function test_scan_failure_extra_caps_captured_stderr()
    {
        $binary = $this->stubBinary('head -c 5000 /dev/zero | tr \'\0\' \'e\' >&2; exit 1');

        try {
            $this->scanner($binary)->scan([$this->targetFile]);
            $this->fail('Expected MalwareScanFailedException');
        } catch (MalwareScanFailedException $e) {
            $this->assertLessThanOrEqual(1000, strlen($e->getLogExtra()['stderr']));
        }
    }

    public function test_canary_content_is_the_eicar_test_string()
    {
        $this->assertSame(
            '275a021bbfb6489e54d471899f7db9d1663fc695ec2fe2a2c4538aabf651fd0f',
            hash('sha256', YaraXScanner::canaryContent()),
        );
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
