<?php

namespace NuiMarkets\LaravelSharedUtils\Services;

use Illuminate\Support\Facades\Log;
use JsonException;
use NuiMarkets\LaravelSharedUtils\Contracts\MalwareScanner;
use NuiMarkets\LaravelSharedUtils\Exceptions\MalwareScanFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * MalwareScanner backed by the YARA-X CLI (`yr scan`), invoked as a separate
 * process so a scanner crash can never take the PHP worker down.
 *
 * CLI contract (verified against yara-x 1.19.0):
 *  - `yr scan --output-format=json <rules> <target>` prints a single JSON object
 *    `{"version": "...", "matches": [{"rule": "...", "file": "..."}]}`.
 *  - Exit code 0 for BOTH match and clean; the verdict is only in `matches`.
 *  - An unreadable/missing TARGET also exits 0 with empty matches and the error
 *    on stderr only — parsing stdout alone would silently fail open, so any
 *    stderr output fails the scan.
 *  - A missing/invalid RULES path exits non-zero.
 *  - `scan` takes exactly one TARGET, which may be a directory. A directory
 *    target scans the files directly inside it, reporting each match with its
 *    absolute path. That is how a batch is scanned in one ruleset load.
 *  - A symlink inside a directory target is SKIPPED, silently, with no stderr
 *    and no match — a fail-open path in a fail-closed control. Batch staging
 *    therefore hard-links or copies, never symlinks, and verifies what landed.
 *  - An *unreadable regular* file inside a directory target is different: it is
 *    skipped but reported on stderr, so the any-stderr rule already fails that
 *    batch. The symlink case is the only silent skip found.
 */
class YaraXScanner implements MalwareScanner
{
    /**
     * Cap on engine stdout/stderr captured into exception log context, so a
     * misbehaving scanner can't flood logs or Sentry payloads.
     */
    private const OUTPUT_CAP = 1000;

    /**
     * Name of the known-malicious file staged alongside every batch. The engine
     * reporting no matches is indistinguishable from the engine having scanned
     * nothing, so the batch carries its own positive control: if the canary
     * doesn't come back matched, the verdict on the real files is not trusted.
     *
     * It proves the engine ran this directory against a ruleset that can match
     * something, which is what catches a rules_path pointing at the wrong file.
     * It does not prove per-file coverage; that comes from staging every entry
     * as a verified regular file, and from any skip the engine does report
     * landing on stderr.
     */
    public const CANARY_NAME = 'zz-scan-canary';

    public function __construct(
        protected string $binary,
        protected string $rulesPath,
        protected bool $compiledRules = false,
        protected int $timeoutSeconds = 10,
    ) {}

    /**
     * Build a scanner from an `attachments.malware_scan` shaped config array.
     */
    public static function fromConfig(array $config): self
    {
        $rulesPath = $config['rules_path'] ?? null;
        $useDefaultRules = ! is_string($rulesPath) || $rulesPath === '';
        if ($useDefaultRules) {
            $rulesPath = self::defaultRulesPath();
        }

        $timeoutSeconds = (int) ($config['timeout_seconds'] ?? 10);
        if ($timeoutSeconds <= 0) {
            $timeoutSeconds = 10;
        }

        return new self(
            binary: $config['binary'] ?? 'yr',
            rulesPath: $rulesPath,
            // The baseline ruleset is plain .yar source; honoring a stray
            // compiled_rules=true against it would fail every scan (closed).
            // The flag only applies to an explicitly configured rules_path.
            compiledRules: ! $useDefaultRules && (bool) ($config['compiled_rules'] ?? false),
            timeoutSeconds: $timeoutSeconds,
        );
    }

    /**
     * Baseline ruleset shipped with the package (EICAR + generic indicators).
     */
    public static function defaultRulesPath(): string
    {
        return dirname(__DIR__, 2).'/resources/malware-rules';
    }

    /**
     * The EICAR test string, assembled at runtime so the package artifact
     * itself doesn't carry a literal antivirus trigger.
     */
    public static function canaryContent(): string
    {
        return 'X5O!P%@AP[4\PZX54(P^)7CC)7}$'.'EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, list<string>>
     */
    public function scan(array $paths): array
    {
        $paths = array_values($paths);

        if ($paths === []) {
            return [];
        }

        foreach ($paths as $path) {
            if (! is_file($path) || ! is_readable($path)) {
                throw new MalwareScanFailedException('Malware scan target is not readable.');
            }
        }

        if (! file_exists($this->rulesPath) || ! is_readable($this->rulesPath)) {
            throw new MalwareScanFailedException('Malware scan rules path is not readable.', extra: [
                'rules_path' => $this->rulesPath,
            ]);
        }

        [$dir, $staged] = $this->stageBatch($paths);

        try {
            return $this->mapMatches($this->runEngine($dir), $staged, $dir.'/'.self::CANARY_NAME);
        } finally {
            $this->removeBatch($dir);
        }
    }

    /**
     * Assemble the batch in a private directory the engine can scan in one pass.
     *
     * Hard-links where the filesystem allows it and copies otherwise, because a
     * symlinked entry is skipped by the engine without any error and would read
     * back as clean.
     *
     * @param  list<string>  $paths
     * @return array{0: string, 1: array<string, string>} Staging directory, and staged path => source path
     */
    protected function stageBatch(array $paths): array
    {
        $dir = sys_get_temp_dir().'/malware-scan-'.bin2hex(random_bytes(8));

        if (! @mkdir($dir, 0700) && ! is_dir($dir)) {
            throw new MalwareScanFailedException('Could not create the malware scan staging directory.');
        }

        // Canonicalise once, so the paths the engine echoes back can be matched
        // exactly rather than by basename, whatever the temp directory resolves
        // through.
        $dir = realpath($dir) ?: $dir;

        $staged = [];

        try {
            foreach ($paths as $i => $path) {
                $target = $dir.'/f'.$i;

                // Link from the resolved path: link() does not follow symlinks,
                // so a symlinked source would otherwise stage as a symlink and
                // be skipped by the engine.
                $source = realpath($path);

                if ($source === false) {
                    throw new MalwareScanFailedException('Malware scan target is not readable.');
                }

                if (! @link($source, $target) && ! @copy($source, $target)) {
                    throw new MalwareScanFailedException('Could not stage an upload for the malware scan.');
                }

                // Verify what landed rather than trusting the staging call: an
                // entry the engine would skip must fail the scan, not pass it.
                clearstatcache(true, $target);
                if (is_link($target) || ! is_file($target) || filesize($target) !== filesize($path)) {
                    throw new MalwareScanFailedException('A staged malware scan target did not match its source.');
                }

                $staged[$target] = $path;
            }

            $canary = self::canaryContent();

            if (file_put_contents($dir.'/'.self::CANARY_NAME, $canary) !== strlen($canary)) {
                throw new MalwareScanFailedException('Could not stage the malware scan canary.');
            }
        } catch (Throwable $e) {
            $this->removeBatch($dir);
            throw $e;
        }

        return [$dir, $staged];
    }

    /**
     * Remove the staging directory. A leak here would accumulate copies of
     * uploaded files and a live EICAR sample under the temp directory, so it is
     * reported rather than swallowed, even though it cannot change the verdict.
     */
    protected function removeBatch(string $dir): void
    {
        // Not glob(), which skips dotfiles and would leave the directory
        // undeletable without saying so.
        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            @unlink($dir.'/'.$entry);
        }

        if (! @rmdir($dir) && is_dir($dir)) {
            Log::warning('Could not remove the malware scan staging directory', [
                'directory' => $dir,
            ]);
        }
    }

    /**
     * Run one engine invocation over the whole staging directory.
     */
    protected function runEngine(string $dir): string
    {
        $command = [$this->binary, 'scan', '--output-format=json', '--disable-warnings'];
        if ($this->compiledRules) {
            $command[] = '--compiled-rules';
        }
        $command[] = '--';
        $command[] = $this->rulesPath;
        $command[] = $dir;

        $process = new Process($command);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new MalwareScanFailedException('Malware scan timed out.', $e, [
                'timeout_seconds' => $this->timeoutSeconds,
            ]);
        } catch (Throwable $e) {
            // Process start failures, e.g. binary missing or not executable.
            throw new MalwareScanFailedException('Malware scanner could not be executed.', $e, [
                'binary' => $this->binary,
            ]);
        }

        $stderr = trim($process->getErrorOutput());

        if (! $process->isSuccessful() || $stderr !== '') {
            throw new MalwareScanFailedException('Malware scan did not complete cleanly.', null, [
                'exit_code' => $process->getExitCode(),
                'stderr' => $this->capOutput($stderr),
            ]);
        }

        return $process->getOutput();
    }

    /**
     * Cap engine output for logs/Sentry without splitting a multibyte
     * character at the boundary.
     */
    private function capOutput(string $output): string
    {
        return mb_strcut($output, 0, self::OUTPUT_CAP);
    }

    /**
     * Turn the engine's JSON stdout into source path => matched rules. Strict:
     * anything other than a well-formed `matches` list of `{rule, file}` entries
     * is a scan failure, never a clean verdict, and so is a batch whose canary
     * went unmatched or which reports a file that was never staged.
     *
     * @param  array<string, string>  $staged  Staged path => source path
     * @param  string  $canaryPath  Staged path of the canary
     * @return array<string, list<string>>
     */
    protected function mapMatches(string $stdout, array $staged, string $canaryPath): array
    {
        try {
            $decoded = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MalwareScanFailedException('Malware scan output was not valid JSON.', $e, [
                'stdout' => $this->capOutput($stdout),
            ]);
        }

        if (! is_array($decoded) || ! isset($decoded['matches']) || ! is_array($decoded['matches'])) {
            throw new MalwareScanFailedException('Malware scan output had no matches list.', null, [
                'stdout' => $this->capOutput($stdout),
            ]);
        }

        $byPath = [];

        foreach ($decoded['matches'] as $match) {
            $rule = is_array($match) ? ($match['rule'] ?? null) : null;
            $file = is_array($match) ? ($match['file'] ?? null) : null;

            if (! is_string($rule) || $rule === '' || ! is_string($file) || $file === '') {
                throw new MalwareScanFailedException('Malware scan output contained a malformed match entry.', null, [
                    'stdout' => $this->capOutput($stdout),
                ]);
            }

            $byPath[$file][] = $rule;
        }

        // Matched by exact staged path, not basename: the canary's authority
        // comes from being the file this invocation planted in this directory,
        // and a same-named file anywhere else must not stand in for it.
        if (! isset($byPath[$canaryPath])) {
            throw new MalwareScanFailedException(
                'Malware scan did not flag its own canary, so the ruleset did not cover the batch.',
                null,
                ['rules_path' => $this->rulesPath],
            );
        }

        unset($byPath[$canaryPath]);

        $results = [];

        foreach ($byPath as $path => $rules) {
            if (! isset($staged[$path])) {
                throw new MalwareScanFailedException('Malware scan reported a match on a file it was not given.', null, [
                    'file' => $path,
                ]);
            }

            $results[$staged[$path]] = array_values(array_unique($rules));
        }

        return $results;
    }
}
