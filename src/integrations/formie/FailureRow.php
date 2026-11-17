<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\integrations\formie;

use stimmt\craft\Mcp\integrations\ErrorText;
use stimmt\craft\Mcp\integrations\Lookback;

/**
 * One failed Formie delivery attempt (a row of formie_delivery_attempts),
 * shaped into what stimmt_forms_failures returns.
 *
 * WHY an allowlist of output keys rather than the row minus a few: the
 * attempt's `data` and `response` columns and the result's `diagnostics` hold
 * the payload that was sent, which is the visitor's submission. Building the
 * row key by key means a column Formie adds later cannot leak by default.
 */
final class FailureRow {
    /**
     * Formie 4 stores these for a failure; the last two are retired statuses
     * that IntegrationResult's constructor now reads as "failed"
     * (verbb/formie src/models/IntegrationResult.php:95-96).
     */
    public const array FAILED_STATUSES = ['failed', 'rejected', 'unknown'];

    /**
     * @param array<string, mixed> $attempt a formie_delivery_attempts row
     * @param array<int, string> $formHandles form id => handle
     * @param array<string, string> $bindingNames binding => display name
     * @return array{submissionId: int, form: ?string, integration: string, kind: string, status: string,
     *     error: string, dateFailed: ?string, retryable: bool}
     */
    public static function shape(array $attempt, array $formHandles, array $bindingNames): array {
        $binding = (string) $attempt['binding'];

        return [
            'submissionId' => (int) $attempt['submissionId'],
            'form' => $formHandles[(int) $attempt['formId']] ?? null,
            'integration' => $bindingNames[$binding] ?? $binding,
            'kind' => $attempt['step'] === 'notification' ? 'notification' : 'integration',
            // Every stored failure status is one failure to Formie now.
            'status' => 'failed',
            'error' => self::error($attempt['result'] ?? null),
            'dateFailed' => Lookback::utc(self::string($attempt['completedAt'] ?? null))
                ?? Lookback::utc(self::string($attempt['dateUpdated'] ?? null)),
            // Formie 4 makes every failure retryable from the submission's
            // control panel page (IntegrationResult.php:105).
            'retryable' => true,
        ];
    }

    private static function error(mixed $stored): string {
        $result = is_string($stored) ? json_decode($stored, true) : null;

        if (!is_array($result)) {
            return 'No result recorded.';
        }

        // DeliveryDiagnostics::encode() keeps a raw JSON prefix when a result
        // is too large. That prefix can cut through the payload, so it is
        // never shown.
        if (($result['truncated'] ?? false) === true) {
            return 'Formie stored a truncated result; open the submission in the control panel for detail.';
        }

        $message = trim((string) ($result['message'] ?? ''));
        $text = $message !== '' ? $message : trim((string) ($result['code'] ?? ''));

        return $text === '' ? 'No error recorded.' : ErrorText::clean($text);
    }

    private static function string(mixed $value): ?string {
        return is_string($value) ? $value : null;
    }
}
