<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Malware Scanning
    |--------------------------------------------------------------------------
    |
    | Opt-in malware scanning of uploads processed by AttachmentService. When
    | enabled, every raw uploaded file is scanned BEFORE any resize or S3
    | upload; a match rejects the whole request (HTTP 422) and stores nothing.
    |
    | Disabled by default: with `enabled` false the scanner is never resolved
    | and processAttachments() behaves exactly as before this feature existed.
    | Enabling requires the YARA-X CLI binary and a ruleset to be present in
    | the runtime image — see docs/malware-scanning.md.
    |
    */
    'malware_scan' => [
        'enabled' => (bool) env('ATTACHMENTS_MALWARE_SCAN_ENABLED', false),

        // YARA-X CLI binary: bare name resolved via PATH, or an absolute path.
        'binary' => env('ATTACHMENTS_MALWARE_SCAN_BINARY', 'yr'),

        // A .yar file, a directory of .yar files, or a compiled .yarc bundle
        // (set compiled_rules=true for the latter). Null uses the baseline
        // ruleset shipped with this package (EICAR + generic indicators) —
        // production services should point this at a curated ruleset.
        // Operator config only: never derive this from request input.
        'rules_path' => env('ATTACHMENTS_MALWARE_SCAN_RULES_PATH'),

        // Set true when rules_path is a precompiled .yarc bundle (yr compile).
        'compiled_rules' => (bool) env('ATTACHMENTS_MALWARE_SCAN_COMPILED_RULES', false),

        // Per-file scan timeout; a timeout is a scan failure, not a clean pass.
        'timeout_seconds' => (int) env('ATTACHMENTS_MALWARE_SCAN_TIMEOUT', 10),

        // DANGEROUS: when true, a scan FAILURE (engine missing/broken/timeout —
        // not a detection) logs a warning and lets the upload through instead
        // of rejecting with HTTP 503. Detections always reject regardless.
        'fail_open' => (bool) env('ATTACHMENTS_MALWARE_SCAN_FAIL_OPEN', false),
    ],
];
