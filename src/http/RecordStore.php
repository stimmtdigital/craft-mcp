<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http;

use craft\helpers\Db;
use DateTimeImmutable;
use RuntimeException;
use stimmt\craft\Mcp\records\Token as Record;

/**
 * TokenStore backed by the mcp_tokens table.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class RecordStore implements TokenStore {
    public function findByHash(string $hash): ?Token {
        $record = Record::findOne(['tokenHash' => $hash]);

        return $record === null ? null : $this->toToken($record);
    }

    public function insert(Token $token, string $hash): Token {
        $record = new Record();
        $record->name = $token->name;
        $record->tokenHash = $hash;
        $record->userId = $token->userId;
        $record->scope = $token->scope->value;
        $record->expiryDate = Db::prepareDateForDb($token->expiryDate);

        if (!$record->save(false)) {
            throw new RuntimeException('Failed to persist the token');
        }

        return $this->toToken($record);
    }

    public function delete(int $id): bool {
        return Record::deleteAll(['id' => $id]) > 0;
    }

    public function all(): array {
        $tokens = [];
        foreach (Record::find()->orderBy(['id' => SORT_ASC])->all() as $record) {
            if ($record instanceof Record) {
                $tokens[] = $this->toToken($record);
            }
        }

        return $tokens;
    }

    public function touch(int $id, DateTimeImmutable $when): void {
        Record::updateAll(['lastUsedAt' => Db::prepareDateForDb($when)], ['id' => $id]);
    }

    private function toToken(Record $record): Token {
        return new Token(
            $record->name,
            (int) $record->userId,
            Scope::from($record->scope),
            $record->expiryDate === null ? null : new DateTimeImmutable($record->expiryDate),
            $record->lastUsedAt === null ? null : new DateTimeImmutable($record->lastUsedAt),
            (int) $record->id,
        );
    }
}
