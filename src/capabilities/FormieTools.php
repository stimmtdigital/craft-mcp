<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\capabilities;

use Craft;
use craft\mcp\attributes\RequiresAdmin;
use DateTimeImmutable;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use stimmt\craft\Mcp\integrations\formie\DeliveryFailures;
use stimmt\craft\Mcp\integrations\formie\FailureRow;
use stimmt\craft\Mcp\integrations\Lookback;
use stimmt\craft\Mcp\integrations\PluginGate;
use stimmt\craft\Mcp\support\Budget;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\Window;
use verbb\formie\elements\Form;
use verbb\formie\Formie;
use verbb\formie\services\DeliveryAttempts;

/**
 * Formie reads: submissions whose CRM, webhook or email deliveries failed,
 * with the error each one ended on.
 *
 * Only registered when Formie 4 is installed and enabled and its delivery
 * table exists. Admin-only, because every row is a submission and therefore
 * a person: the tool returns ids, the form, the integration and a scrubbed
 * error, never a field value.
 */
final readonly class FormieTools {
    /** The major that records deliveries in DeliveryAttempts::TABLE. */
    private const string MIN_VERSION = '4.0.0';

    private DeliveryFailures $failures;

    public function __construct(?DeliveryFailures $failures = null) {
        $this->failures = $failures ?? new DeliveryFailures();
    }

    /**
     * WHY the table as well as the version: the delivery table arrives with a
     * Formie 4 migration (m260927_000000_delivery_attempts), so a site that
     * updated the code but has not run migrations yet has the version and not
     * the table.
     */
    public static function isAvailable(): bool {
        return class_exists(DeliveryAttempts::class)
            && PluginGate::enabledAtLeast('formie', self::MIN_VERSION)
            && Craft::$app->getDb()->getTableSchema(DeliveryAttempts::TABLE) !== null;
    }

    #[McpTool(
        name: 'stimmt_forms_failures',
        title: 'Failed form integrations',
        description: 'Formie submissions whose integrations (CRM, webhook, email marketing) or email notifications'
            . ' failed, newest first, with the error message. A failure later retried successfully is left out.'
            . ' Returns submission ids and error text only, never submitted values; emails and phone numbers in'
            . ' errors are scrubbed. Retry from the submission in the Formie control panel.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[RequiresAdmin]
    public function formFailures(
        #[Schema(description: 'Form handle. Omit for failures on every form.')]
        ?string $form = null,
        #[Schema(description: 'Only failures from the last N days, 1 to 3650.', minimum: 1, maximum: Lookback::MAX_DAYS)]
        int $days = 30,
        #[Schema(description: Window::LIMIT_DESCRIPTION, minimum: Window::MIN_LIMIT)]
        int $limit = 20,
        #[Schema(description: Window::OFFSET_DESCRIPTION, minimum: Window::MIN_OFFSET)]
        int $offset = 0,
    ): array {
        Window::assert($limit, $offset);
        $since = Lookback::since($days, new DateTimeImmutable());

        $query = $this->failures->query($this->formId($form), $since);
        $total = (int) (clone $query)->count();
        $attempts = $query->limit($limit)->offset($offset)->all();
        $forms = $this->failures->formHandles($attempts);
        $bindings = $this->failures->bindingNames($attempts);
        $rows = array_map(
            static fn (array $attempt): array => FailureRow::shape($attempt, $forms, $bindings),
            $attempts,
        );

        return Budget::assert([
            ...Response::paginated('failures', $rows, $total, $limit, $offset),
            'since' => $since->format(DATE_ATOM),
        ]);
    }

    private function formId(?string $handle): ?int {
        if ($handle === null) {
            return null;
        }

        $form = Formie::$plugin->getForms()->getFormByHandle($handle);
        if ($form !== null) {
            return (int) $form->id;
        }

        $handles = array_map(
            static fn (Form $form): string => (string) $form->handle,
            Formie::$plugin->getForms()->getAllForms(),
        );

        throw new ToolCallException(
            "Form '{$handle}' not found. "
            . ($handles === [] ? 'This install has no forms.' : 'Available handles: ' . implode(', ', $handles) . '.'),
        );
    }
}
