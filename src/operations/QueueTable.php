<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\operations;

use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\queue\Queue;
use Throwable;
use yii\db\Connection;

/**
 * Read-only queries on the table behind Craft's database queue.
 *
 * WHY not Queue::getTotalFailed() and friends: each of them first moves expired
 * reservations back to waiting (Queue::_moveExpired), an UPDATE. A read-only
 * tool must not write, so the same conditions Craft uses are queried here,
 * against the queue's own table and channel.
 */
final readonly class QueueTable {
    public function __construct(
        private Queue $queue,
    ) {
    }

    public function failed(): int {
        return (int) $this->jobs()->andWhere(['fail' => true])->count('*', $this->db());
    }

    public function waiting(): int {
        return (int) $this->waitingJobs()->count('*', $this->db());
    }

    /**
     * Seconds since the longest-waiting job became due, null when none waits.
     */
    public function oldestWaitingAge(): ?int {
        $due = $this->waitingJobs()->min('[[timePushed]] + [[delay]]', $this->db());

        return is_numeric($due) ? max(0, DateTimeHelper::currentTimeStamp() - (int) $due) : null;
    }

    /**
     * One page of failed jobs, most recently failed first. The job payload is
     * deliberately not selected.
     *
     * @return list<array<string, mixed>>
     */
    public function failedPage(int $limit, int $offset): array {
        return array_values($this->jobs()
            ->select(['id', 'description', 'timePushed', 'attempt', 'dateFailed', 'error'])
            ->andWhere(['fail' => true])
            ->orderBy(['dateFailed' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->offset($offset)
            ->all($this->db()));
    }

    /**
     * The job's class, which Craft reads by unserializing the payload. Null
     * when that fails, for instance when the plugin that pushed it is gone.
     */
    public function jobClass(int $id): ?string {
        try {
            $class = $this->queue->getJobDetails((string) $id)['class'] ?? null;
        } catch (Throwable) {
            return null;
        }

        return is_string($class) ? $class : null;
    }

    private function jobs(): Query {
        return (new Query())
            ->from($this->queue->tableName)
            ->where(['channel' => $this->channel()]);
    }

    /**
     * Craft's own definition of waiting (Queue::_createWaitingJobQuery):
     * not failed, not reserved, and due.
     */
    private function waitingJobs(): Query {
        return $this->jobs()
            ->andWhere(['fail' => false, 'timeUpdated' => null])
            ->andWhere('[[timePushed]] + [[delay]] <= :time', ['time' => DateTimeHelper::currentTimeStamp()]);
    }

    /**
     * Craft's Queue::channel() is private: the configured channel, else the
     * queue's component id. Callers reach this queue as Craft::$app->getQueue(),
     * which is the `queue` component.
     */
    private function channel(): string {
        return $this->queue->channel ?? 'queue';
    }

    private function db(): ?Connection {
        return $this->queue->db instanceof Connection ? $this->queue->db : null;
    }
}
