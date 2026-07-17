<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use DateTimeImmutable;
use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Session store backed by the mcp_sessions table, so HTTP transport
 * sessions are shared across app instances behind a load balancer.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class DbSessionStore implements SessionStoreInterface {
    private const string TABLE = '{{%mcp_sessions}}';

    public function __construct(private int $ttl = 3600) {
    }

    public function exists(Uuid $id): bool {
        return (new Query())
            ->from(self::TABLE)
            ->where(['id' => $id->toRfc4122()])
            ->andWhere(['>=', 'dateUpdated', $this->oldestAlive()])
            ->exists();
    }

    public function read(Uuid $id): string|false {
        $data = (new Query())
            ->select(['data'])
            ->from(self::TABLE)
            ->where(['id' => $id->toRfc4122()])
            ->andWhere(['>=', 'dateUpdated', $this->oldestAlive()])
            ->scalar();

        return is_string($data) ? $data : false;
    }

    public function write(Uuid $id, string $data): bool {
        $now = Db::prepareDateForDb(new DateTimeImmutable());

        // Upsert reports 0 affected rows when the payload is unchanged, so
        // the return value cannot signal failure; exceptions do.
        Craft::$app->getDb()->createCommand()->upsert(
            self::TABLE,
            ['id' => $id->toRfc4122(), 'data' => $data, 'dateCreated' => $now, 'dateUpdated' => $now],
            ['data' => $data, 'dateUpdated' => $now],
        )->execute();

        return true;
    }

    public function destroy(Uuid $id): bool {
        return Craft::$app->getDb()->createCommand()
            ->delete(self::TABLE, ['id' => $id->toRfc4122()])
            ->execute() > 0;
    }

    public function gc(): array {
        $expired = (new Query())
            ->select(['id'])
            ->from(self::TABLE)
            ->where(['<', 'dateUpdated', $this->oldestAlive()])
            ->column();

        if ($expired !== []) {
            Craft::$app->getDb()->createCommand()->delete(self::TABLE, ['id' => $expired])->execute();
        }

        return array_map(Uuid::fromString(...), $expired);
    }

    private function oldestAlive(): string {
        return Db::prepareDateForDb((new DateTimeImmutable())->modify(sprintf('-%d seconds', $this->ttl)));
    }
}
