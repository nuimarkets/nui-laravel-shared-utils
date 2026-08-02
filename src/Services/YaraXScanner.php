<?php

namespace NuiMarkets\LaravelSharedUtils\Services;

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
 *  - `yr scan --output-format=json <rules> <file>` prints a single JSON object
 *    `{"version": "...", "matches": [{"rule": "...", "file": "..."}]}`.
 *  - Exit code 0 for BOTH match and clean; the verdict is only in `matches`.
 *  - An unreadable/missing TARGET file also exits 0 with empty matches and the
 *    error on stderr only — parsing stdout alone would silently fail open, so
 *    any stderr output fails the scan.
 *  - A missing/invalid RULES path exits non-zero.
 */
class YaraXScanner implements MalwareScanner
{
    /**
     * Cap on engine stdout/stderr captured into exception extra, so a
     * misbehaving scanner can't flood logs or Sentry payloads.
     */
    private const OUTPUT_CAP = 1000;

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

    public function scan(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new MalwareScanFailedException('Malware scan target is not readable.');
        }

        if (! file_exists($this->rulesPath) || ! is_readable($this->rulesPath)) {
            throw new MalwareScanFailedException('Malware scan rules path is not readable.', extra: [
                'rules_path' => $this->rulesPath,
            ]);
        }

        $command = [$this->binary, 'scan', '--output-format=json', '--disable-warnings'];
        if ($this->compiledRules) {
            $command[] = '--compiled-rules';
        }
        $command[] = '--';
        $command[] = $this->rulesPath;
        $command[] = $path;

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

        return $this->parseMatches($process->getOutput());
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
     * Parse matched rule identifiers out of the engine's JSON stdout. Strict:
     * anything other than a well-formed `matches` list of `{rule: string}`
     * entries is a scan failure, never a clean verdict.
     *
     * @return list<string>
     */
    protected function parseMatches(string $stdout): array
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

        $rules = [];

        foreach ($decoded['matches'] as $match) {
            $rule = is_array($match) ? ($match['rule'] ?? null) : null;

            if (! is_string($rule) || $rule === '') {
                throw new MalwareScanFailedException('Malware scan output contained a malformed match entry.', null, [
                    'stdout' => $this->capOutput($stdout),
                ]);
            }

            $rules[] = $rule;
        }

        return array_values(array_unique($rules));
    }
}
