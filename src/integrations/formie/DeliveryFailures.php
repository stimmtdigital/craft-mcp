<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\integrations\formie;

use craft\db\Query;
use craft\helpers\Db;
use DateTimeImmutable;
use verbb\formie\enums\IntegrationStatus;
use verbb\formie\Formie;
use verbb\formie\services\DeliveryAttempts;

/**
 * Formie 4's failed deliveries: one row per integration or email
 * notification attempt that ended in failure.
 *
 * WHERE they live: Formie 4 records every delivery in its own table
 * (DeliveryAttempts::TABLE, verbb/formie src/services/DeliveryAttempts.php:39),
 * one row per submission, binding and execution. The binding is the
 * integration's handle, or "notification:<uid|id>" for an email
 * (services/Notifications.php:171, base/Integration.php:1029); the step is
 * "integration" or "notification" for those top-level deliveries, while
 * child write steps, the "dispatch" batch and "finalized" markers repeat what
 * the top-level row already says.
 *
 * WHY a query rather than the service: DeliveryAttempts only reads by attempt
 * uid or by one submission (history(), DeliveryAttempts.php:351); nothing in
 * Formie lists failures across submissions.
 */
final class DeliveryFailures {
    private const array STEPS = ['integration', 'notification'];

    private const string NOTIFICATION_PREFIX = 'notification:';

    public function query(?int $formId, DateTimeImmutable $since): Query {
        $query = (new Query())
            ->select(['a.id', 'a.submissionId', 'a.formId', 'a.binding', 'a.step', 'a.status', 'a.result',
                'a.completedAt', 'a.dateUpdated'])
            ->from(['a' => DeliveryAttempts::TABLE])
            ->where(['a.status' => $this->failedStatuses(), 'a.step' => self::STEPS])
            ->andWhere(['>=', 'a.dateUpdated', Db::prepareDateForDb($since)])
            ->andWhere(['not exists', $this->laterSuccess()])
            ->orderBy(['a.dateUpdated' => SORT_DESC, 'a.id' => SORT_DESC]);

        if ($formId !== null) {
            $query->andWhere(['a.formId' => $formId]);
        }

        return $query;
    }

    /**
     * Form handles for the forms on one page of rows, by id.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, string>
     */
    public function formHandles(array $rows): array {
        $handles = [];
        foreach (array_unique(array_map(static fn (array $row): int => (int) $row['formId'], $rows)) as $id) {
            $handle = Formie::$plugin->getForms()->getFormById($id)?->handle;
            if ($handle !== null) {
                $handles[$id] = (string) $handle;
            }
        }

        return $handles;
    }

    /**
     * Display names for the bindings on one page of rows.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, string>
     */
    public function bindingNames(array $rows): array {
        $bindings = array_unique(array_map(static fn (array $row): string => (string) $row['binding'], $rows));
        $notifications = $this->notificationNames();
        $names = [];

        foreach ($bindings as $binding) {
            $name = str_starts_with($binding, self::NOTIFICATION_PREFIX)
                ? $notifications[substr($binding, strlen(self::NOTIFICATION_PREFIX))] ?? null
                : Formie::$plugin->getIntegrations()->getIntegrationByHandle($binding)?->name;

            if ($name !== null && $name !== '') {
                $names[$binding] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * The statuses Formie stores for a failure. Rejected and Unknown are
     * retired but still present in rows from earlier releases, and Formie
     * reads both as Failed (models/IntegrationResult.php:95-96).
     *
     * @return list<string>
     */
    private function failedStatuses(): array {
        return [
            IntegrationStatus::Failed->value,
            IntegrationStatus::Rejected->value,
            IntegrationStatus::Unknown->value,
        ];
    }

    /**
     * A later attempt of the same delivery that succeeded. A retry in a new
     * execution writes a new row (the identity hashes the execution uid,
     * DeliveryAttempts.php:57), so without this the original failure would be
     * reported after it was fixed.
     */
    private function laterSuccess(): Query {
        return (new Query())
            ->select('ok.id')
            ->from(['ok' => DeliveryAttempts::TABLE])
            ->where('[[ok.submissionId]] = [[a.submissionId]]')
            ->andWhere('[[ok.binding]] = [[a.binding]]')
            ->andWhere('[[ok.step]] = [[a.step]]')
            ->andWhere('[[ok.id]] > [[a.id]]')
            ->andWhere(['ok.status' => IntegrationStatus::Succeeded->value]);
    }

    /**
     * Notification names keyed the way Formie writes the binding: the uid
     * when there is one, the id otherwise (services/Notifications.php:171).
     *
     * @return array<string, string>
     */
    private function notificationNames(): array {
        $names = [];
        foreach (Formie::$plugin->getNotifications()->getAllNotifications() as $notification) {
            $names[(string) ($notification->uid ?: $notification->id)] = (string) $notification->name;
        }

        return $names;
    }
}
