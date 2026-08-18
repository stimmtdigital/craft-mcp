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
 * Reads and redundant writes are collapsed through a request-scoped
 * SessionCache; that class holds the reasoning for why one request only needs
 * one of each.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class DbSessionStore implements SessionStoreInterface {
    private const string TABLE = '{{%mcp_sessions}}';

    public function __construct(private int $ttl = 3600, private SessionCache $cache = new SessionCache()) {
    }

    /**
     * Answered from the payload rather than a SELECT of its own: both questions
     * are "is there a row for this id inside the TTL window", and read() is the
     * one whose answer is worth keeping. The SDK asks exists() immediately
     * before building the Session that reads, so this pays for both.
     */
    public function exists(Uuid $id): bool {
        return $this->read($id) !== false;
    }

    public function read(Uuid $id): string|false {
        return $this->cache->remember($id, fn (): string|false => $this->select($id));
    }

    public function write(Uuid $id, string $data): bool {
        if ($this->cache->isStored($id, $data)) {
            return true;
        }

        $now = Db::prepareDateForDb(new DateTimeImmutable());

        // Upsert reports 0 affected rows when the payload is unchanged, so
        // the return value cannot signal failure; exceptions do.
        Craft::$app->getDb()->createCommand()->upsert(
            self::TABLE,
            ['id' => $id->toRfc4122(), 'data' => $data, 'dateCreated' => $now, 'dateUpdated' => $now],
            ['data' => $data, 'dateUpdated' => $now],
        )->execute();

        $this->cache->store($id, $data);

        return true;
    }

    public function destroy(Uuid $id): bool {
        // Forgotten first: a delete that threw halfway must not leave a cache
        // entry claiming the row is still there.
        $this->cache->forget($id);

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

        $ids = array_map(Uuid::fromString(...), $expired);
        $this->cache->forget(...$ids);

        return $ids;
    }

    private function select(Uuid $id): string|false {
        $data = (new Query())
            ->select(['data'])
            ->from(self::TABLE)
            ->where(['id' => $id->toRfc4122()])
            ->andWhere(['>=', 'dateUpdated', $this->oldestAlive()])
            ->scalar();

        return is_string($data) ? $data : false;
    }

    private function oldestAlive(): string {
        return Db::prepareDateForDb((new DateTimeImmutable())->modify(sprintf('-%d seconds', $this->ttl)));
    }
}
