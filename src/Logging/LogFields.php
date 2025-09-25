<?php

namespace NuiMarkets\LaravelSharedUtils\Logging;

/**
 * Base class for standard field names following snake_case convention.
 * Services can extend this class to add their own domain-specific fields.
 *
 * PRIVACY NOTE: Fields marked with "PII" contain Personally Identifiable Information.
 * Consider these fields when implementing log redaction policies:
 * - USER_ID, REQUEST_USER_ID, AUDIT_USER_ID: Personal identifiers
 * - USER_EMAIL: Email addresses
 * - USER_NAME: Personal names
 * - REQUEST_IP, AUDIT_IP: IP addresses (may identify individuals)
 *
 * Services should evaluate whether to redact these fields based on:
 * - Privacy compliance requirements (GDPR, CCPA, etc.)
 * - Debugging and operational needs
 * - Data retention and access policies
 *
 * @see https://github.com/nuimarkets/connect-docs/blob/main/php-services/logging.md
 * @see SensitiveDataProcessor for configurable field redaction
 */
abstract class LogFields
{
    // Core identification fields
    const TARGET = 'target';

    const SERVICE = 'service';

    const ENVIRONMENT = 'environment';

    const VERSION = 'version';

    // Request context fields
    const REQUEST_ID = 'request_id';

    const REQUEST_METHOD = 'request.method';

    const REQUEST_PATH = 'request.path';

    const REQUEST_IP = 'request.ip'; // PII: Contains IP address

    const REQUEST_USER_ID = 'request.user_id'; // PII: Personal identifier

    const REQUEST_ORG_ID = 'request.org_id';

    const REQUEST_USER_AGENT = 'request.user_agent';

    const REQUEST_USER_EMAIL = 'request.user_email'; // PII: Email from JWT/session

    const REQUEST_USER_TYPE = 'request.user_type'; // User type from JWT/session

    const REQUEST_HEADERS = 'request.headers';

    const REQUEST_QUERY = 'request.query';

    const REQUEST_BODY = 'request.body';

    // Tracing and correlation fields
    const TRACE_ID = 'request.trace_id';

    const TRACE_ID_HEADER = 'request.amz_trace_id';

    // User and organization fields
    const USER_ID = 'user_id'; // PII: Personal identifier

    const ORG_ID = 'org_id';

    const TENANT_ID = 'tenant_id';

    const USER_TYPE = 'user_type';

    const USER_EMAIL = 'user_email'; // PII: Personal email address

    const USER_NAME = 'user_name'; // PII: Personal name

    // Action and operation fields
    const FEATURE = 'feature';

    const ACTION = 'action';

    const OPERATION = 'operation';

    const EVENT = 'event';

    const STATUS = 'status';

    const RESULT = 'result';

    // Error and exception fields
    const ERROR = 'error';

    const EXCEPTION = 'exception';

    const ERROR_MESSAGE = 'error_message';

    const ERROR_CODE = 'error_code';

    const ERROR_TYPE = 'error_type';

    const ERROR_FILE = 'error_file';

    const ERROR_LINE = 'error_line';

    const ERROR_TRACE = 'error_trace';

    const VALIDATION_ERRORS = 'validation_errors';

    // Performance and metrics fields
    const DURATION_MS = 'duration_ms';

    const MEMORY_MB = 'memory_mb';

    const MEMORY_PEAK_MB = 'memory_peak_mb';

    const QUERY_COUNT = 'query_count';

    const QUERY_TIME_MS = 'query_time_ms';

    const CPU_TIME_MS = 'cpu_time_ms';

    const RESPONSE_SIZE_BYTES = 'response_size_bytes';

    const REQUEST_SIZE_BYTES = 'request_size_bytes';

    // External API fields
    const API_SERVICE = 'api.service';

    const API_ENDPOINT = 'api.endpoint';

    const API_METHOD = 'api.method';

    const API_STATUS = 'api.status';

    const API_DURATION_MS = 'api.duration_ms';

    const API_SUCCESS = 'api.success';

    const API_REQUEST_ID = 'api.request_id';

    const API_ERROR = 'api.error';

    const API_RETRY_COUNT = 'api.retry_count';

    // Database fields
    const DB_CONNECTION = 'db.connection';

    const DB_QUERY = 'db.query';

    const DB_BINDINGS = 'db.bindings';

    const DB_TIME_MS = 'db.time_ms';

    const DB_ROWS_AFFECTED = 'db.rows_affected';

    // Queue and job fields
    const QUEUE_NAME = 'queue.name';

    const QUEUE_CONNECTION = 'queue.connection';

    const JOB_ID = 'job.id';

    const JOB_NAME = 'job.name';

    const JOB_ATTEMPTS = 'job.attempts';

    const JOB_DELAY = 'job.delay';

    const JOB_TIMEOUT = 'job.timeout';

    // Cache fields
    const CACHE_KEY = 'cache.key';

    const CACHE_HIT = 'cache.hit';

    const CACHE_TAGS = 'cache.tags';

    const CACHE_TTL = 'cache.ttl';

    // Business logic fields (common across services)
    const ENTITY_ID = 'entity_id';

    const ENTITY_TYPE = 'entity_type';

    const ENTITY_STATUS = 'entity_status';

    const ENTITY_TOTAL = 'entity_total';

    const ENTITY_COUNT = 'entity_count';

    // Audit and security fields
    const AUDIT_ACTION = 'audit.action';

    const AUDIT_USER_ID = 'audit.user_id'; // PII: Personal identifier

    const AUDIT_IP = 'audit.ip'; // PII: Contains IP address

    const AUDIT_USER_AGENT = 'audit.user_agent';

    const SECURITY_EVENT = 'security.event';

    const SECURITY_THREAT_LEVEL = 'security.threat_level';

    /**
     * Get all defined log field constants.
     * Useful for validation and documentation generation.
     *
     * @return array<string, string>
     */
    public static function getAllFields(): array
    {
        static $cache = [];
        $cls = static::class;
        if (! isset($cache[$cls])) {
            $cache[$cls] = (new \ReflectionClass($cls))->getConstants();
        }

        return $cache[$cls];
    }

    /**
     * Get fields grouped by category.
     * Services can override this to include their custom fields.
     *
     * @return array<string, array<string, string>>
     */
    public static function getFieldsByCategory(): array
    {
        return [
            'core' => [
                'TARGET' => self::TARGET,
                'SERVICE' => self::SERVICE,
                'ENVIRONMENT' => self::ENVIRONMENT,
                'VERSION' => self::VERSION,
            ],
            'request' => [
                'REQUEST_ID' => self::REQUEST_ID,
                'REQUEST_METHOD' => self::REQUEST_METHOD,
                'REQUEST_PATH' => self::REQUEST_PATH,
                'REQUEST_IP' => self::REQUEST_IP,
                'REQUEST_USER_ID' => self::REQUEST_USER_ID,
                'REQUEST_ORG_ID' => self::REQUEST_ORG_ID,
                'REQUEST_USER_AGENT' => self::REQUEST_USER_AGENT,
                'REQUEST_USER_EMAIL' => self::REQUEST_USER_EMAIL,
                'REQUEST_USER_TYPE' => self::REQUEST_USER_TYPE,
                'TRACE_ID' => self::TRACE_ID,
                'TRACE_ID_HEADER' => self::TRACE_ID_HEADER,
            ],
            'user' => [
                'USER_ID' => self::USER_ID,
                'ORG_ID' => self::ORG_ID,
                'TENANT_ID' => self::TENANT_ID,
                'USER_TYPE' => self::USER_TYPE,
                'USER_EMAIL' => self::USER_EMAIL,
                'USER_NAME' => self::USER_NAME,
            ],
            'action' => [
                'FEATURE' => self::FEATURE,
                'ACTION' => self::ACTION,
                'OPERATION' => self::OPERATION,
                'EVENT' => self::EVENT,
                'STATUS' => self::STATUS,
                'RESULT' => self::RESULT,
            ],
            'error' => [
                'ERROR' => self::ERROR,
                'EXCEPTION' => self::EXCEPTION,
                'ERROR_MESSAGE' => self::ERROR_MESSAGE,
                'ERROR_CODE' => self::ERROR_CODE,
                'ERROR_TYPE' => self::ERROR_TYPE,
                'ERROR_FILE' => self::ERROR_FILE,
                'ERROR_LINE' => self::ERROR_LINE,
                'ERROR_TRACE' => self::ERROR_TRACE,
                'VALIDATION_ERRORS' => self::VALIDATION_ERRORS,
            ],
            'performance' => [
                'DURATION_MS' => self::DURATION_MS,
                'MEMORY_MB' => self::MEMORY_MB,
                'MEMORY_PEAK_MB' => self::MEMORY_PEAK_MB,
                'QUERY_COUNT' => self::QUERY_COUNT,
                'QUERY_TIME_MS' => self::QUERY_TIME_MS,
                'CPU_TIME_MS' => self::CPU_TIME_MS,
                'RESPONSE_SIZE_BYTES' => self::RESPONSE_SIZE_BYTES,
                'REQUEST_SIZE_BYTES' => self::REQUEST_SIZE_BYTES,
            ],
            'api' => [
                'API_SERVICE' => self::API_SERVICE,
                'API_ENDPOINT' => self::API_ENDPOINT,
                'API_METHOD' => self::API_METHOD,
                'API_STATUS' => self::API_STATUS,
                'API_DURATION_MS' => self::API_DURATION_MS,
                'API_SUCCESS' => self::API_SUCCESS,
                'API_REQUEST_ID' => self::API_REQUEST_ID,
                'API_ERROR' => self::API_ERROR,
                'API_RETRY_COUNT' => self::API_RETRY_COUNT,
            ],
            'entity' => [
                'ENTITY_ID' => self::ENTITY_ID,
                'ENTITY_TYPE' => self::ENTITY_TYPE,
                'ENTITY_STATUS' => self::ENTITY_STATUS,
                'ENTITY_TOTAL' => self::ENTITY_TOTAL,
                'ENTITY_COUNT' => self::ENTITY_COUNT,
            ],
            'database' => [
                'DB_CONNECTION' => self::DB_CONNECTION,
                'DB_QUERY' => self::DB_QUERY,
                'DB_BINDINGS' => self::DB_BINDINGS,
                'DB_TIME_MS' => self::DB_TIME_MS,
                'DB_ROWS_AFFECTED' => self::DB_ROWS_AFFECTED,
            ],
            'queue' => [
                'QUEUE_NAME' => self::QUEUE_NAME,
                'QUEUE_CONNECTION' => self::QUEUE_CONNECTION,
                'JOB_ID' => self::JOB_ID,
                'JOB_NAME' => self::JOB_NAME,
                'JOB_ATTEMPTS' => self::JOB_ATTEMPTS,
                'JOB_DELAY' => self::JOB_DELAY,
                'JOB_TIMEOUT' => self::JOB_TIMEOUT,
            ],
            'cache' => [
                'CACHE_KEY' => self::CACHE_KEY,
                'CACHE_HIT' => self::CACHE_HIT,
                'CACHE_TAGS' => self::CACHE_TAGS,
                'CACHE_TTL' => self::CACHE_TTL,
            ],
            'audit' => [
                'AUDIT_ACTION' => self::AUDIT_ACTION,
                'AUDIT_USER_ID' => self::AUDIT_USER_ID,
                'AUDIT_IP' => self::AUDIT_IP,
                'AUDIT_USER_AGENT' => self::AUDIT_USER_AGENT,
                'SECURITY_EVENT' => self::SECURITY_EVENT,
                'SECURITY_THREAT_LEVEL' => self::SECURITY_THREAT_LEVEL,
            ],
        ];
    }

    /**
     * Check if a field name is defined in this class or its children.
     */
    public static function isValidField(string $fieldName): bool
    {
        return in_array($fieldName, static::getAllFields(), true);
    }

    /**
     * Get fields that contain Personally Identifiable Information (PII).
     * These fields may require special handling for privacy compliance.
     *
     * @return array<string, string>
     */
    public static function getPiiFields(): array
    {
        return [
            'REQUEST_IP' => self::REQUEST_IP,
            'REQUEST_USER_ID' => self::REQUEST_USER_ID,
            'REQUEST_USER_EMAIL' => self::REQUEST_USER_EMAIL,
            'USER_ID' => self::USER_ID,
            'USER_EMAIL' => self::USER_EMAIL,
            'USER_NAME' => self::USER_NAME,
            'AUDIT_USER_ID' => self::AUDIT_USER_ID,
            'AUDIT_IP' => self::AUDIT_IP,
        ];
    }

    /**
     * Check if a field contains PII.
     *
     * @param  string  $fieldName  The field name to check (accepts constant name or value)
     * @return bool True if field contains PII
     */
    public static function isPiiField(string $fieldName): bool
    {
        // Accept either the field value (e.g., 'user_email') or the constant key (e.g., 'USER_EMAIL')
        $piiMap = static::getPiiFields();

        return in_array($fieldName, array_values($piiMap), true) || array_key_exists($fieldName, $piiMap);
    }

    /**
     * Get service-specific fields.
     * Services should override this method to return their custom fields.
     *
     * @return array<string, string>
     */
    public static function getServiceSpecificFields(): array
    {
        // Override in service-specific implementations
        return [];
    }
}
