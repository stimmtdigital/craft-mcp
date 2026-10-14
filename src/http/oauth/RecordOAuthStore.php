<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http\oauth;

use craft\helpers\Db;
use craft\helpers\Json;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use stimmt\craft\Mcp\http\Scope;
use stimmt\craft\Mcp\records\OAuthClient as ClientRecord;
use stimmt\craft\Mcp\records\OAuthCode as CodeRecord;
use stimmt\craft\Mcp\records\OAuthGrant as GrantRecord;
use stimmt\craft\Mcp\records\OAuthRefreshToken as RefreshRecord;
use stimmt\craft\Mcp\records\Token as TokenRecord;
use yii\db\ActiveRecord;

/**
 * OAuthStore backed by the mcp_oauth_* tables.
 *
 * @author stimmt.digital
 */
final class RecordOAuthStore implements OAuthStore {
    public function findClient(string $clientId): ?OAuthClient {
        $record = ClientRecord::findOne(['clientId' => $clientId]);

        return $record === null ? null : $this->toClient($record);
    }

    public function findClientById(int $id): ?OAuthClient {
        $record = ClientRecord::findOne(['id' => $id]);

        return $record === null ? null : $this->toClient($record);
    }

    public function insertClient(OAuthClient $client): OAuthClient {
        $record = new ClientRecord();
        $record->setAttributes(['clientId' => $client->clientId, 'name' => $client->name, 'redirectUris' => Json::encode($client->redirectUris)], false);
        $this->save($record);

        return $this->toClient($record);
    }

    public function touchClient(int $id, DateTimeImmutable $when): void {
        ClientRecord::updateAll(['lastUsedAt' => Db::prepareDateForDb($when)], ['id' => $id]);
    }

    public function findGrant(int $id): ?Grant {
        $record = GrantRecord::findOne(['id' => $id]);

        return $record === null ? null : $this->toGrant($record);
    }

    public function insertGrant(Grant $grant): Grant {
        $record = new GrantRecord();
        $record->setAttributes(['clientId' => $grant->clientRecordId, 'userId' => $grant->userId, 'scope' => $grant->scope->value], false);
        $this->save($record);

        return $this->toGrant($record);
    }

    public function revokeGrant(int $id, DateTimeImmutable $when): void {
        GrantRecord::updateAll(['dateRevoked' => Db::prepareDateForDb($when)], ['id' => $id, 'dateRevoked' => null]);
    }

    public function liveGrants(?int $userId = null): array {
        $query = GrantRecord::find()->where(['dateRevoked' => null])->orderBy(['id' => SORT_DESC]);
        if ($userId !== null) {
            $query->andWhere(['userId' => $userId]);
        }

        $grants = [];
        foreach ($query->all() as $record) {
            if ($record instanceof GrantRecord) {
                $grants[] = $this->toGrant($record);
            }
        }

        return $grants;
    }

    public function insertCode(AuthorizationCode $code, string $hash): void {
        $record = new CodeRecord();
        $record->setAttributes([
            'codeHash' => $hash,
            'grantId' => $code->grantId,
            'redirectUri' => $code->redirectUri,
            'codeChallenge' => $code->codeChallenge,
            'expiryDate' => Db::prepareDateForDb($code->expiryDate),
        ], false);
        $this->save($record);
    }

    public function findCode(string $hash): ?AuthorizationCode {
        $record = CodeRecord::findOne(['codeHash' => $hash]);
        if ($record === null) {
            return null;
        }

        return new AuthorizationCode(
            (int) $record->getAttribute('grantId'),
            (string) $record->getAttribute('redirectUri'),
            (string) $record->getAttribute('codeChallenge'),
            $this->date($record->getAttribute('expiryDate')) ?? new DateTimeImmutable('@0'),
            $this->date($record->getAttribute('dateUsed')),
        );
    }

    public function markCodeUsed(string $hash, DateTimeImmutable $when): bool {
        return CodeRecord::updateAll(['dateUsed' => Db::prepareDateForDb($when)], ['codeHash' => $hash, 'dateUsed' => null]) === 1;
    }

    public function insertRefreshToken(RefreshToken $token, string $hash): void {
        $record = new RefreshRecord();
        $record->setAttributes(['tokenHash' => $hash, 'grantId' => $token->grantId, 'expiryDate' => Db::prepareDateForDb($token->expiryDate)], false);
        $this->save($record);
    }

    public function findRefreshToken(string $hash): ?RefreshToken {
        $record = RefreshRecord::findOne(['tokenHash' => $hash]);
        if ($record === null) {
            return null;
        }

        return new RefreshToken(
            (int) $record->getAttribute('grantId'),
            $this->date($record->getAttribute('expiryDate')) ?? new DateTimeImmutable('@0'),
            $this->date($record->getAttribute('dateUsed')),
        );
    }

    public function markRefreshTokenUsed(string $hash, DateTimeImmutable $when): bool {
        return RefreshRecord::updateAll(['dateUsed' => Db::prepareDateForDb($when)], ['tokenHash' => $hash, 'dateUsed' => null]) === 1;
    }

    /**
     * Expired codes and refresh tokens, expired OAuth access tokens, revoked
     * grants (cascading to what hangs off them), and registrations nobody has
     * used: open registration means any caller can add a row, so a client with
     * no live grant and no use since the cutoff is dropped.
     */
    public function collectGarbage(DateTimeImmutable $now, DateTimeImmutable $unusedClientsBefore): int {
        $nowDb = Db::prepareDateForDb($now);
        $cutoff = Db::prepareDateForDb($unusedClientsBefore);
        $removed = CodeRecord::deleteAll(['<', 'expiryDate', $nowDb]);
        $removed += RefreshRecord::deleteAll(['<', 'expiryDate', $nowDb]);
        $removed += TokenRecord::deleteAll(['and', ['not', ['grantId' => null]], ['<', 'expiryDate', $nowDb]]);
        $removed += GrantRecord::deleteAll(['not', ['dateRevoked' => null]]);

        $live = GrantRecord::find()->select(['clientId'])->where(['dateRevoked' => null])->column();
        $stale = ['and', ['<', 'dateCreated', $cutoff], ['or', ['lastUsedAt' => null], ['<', 'lastUsedAt', $cutoff]]];

        return $removed + ClientRecord::deleteAll($live === [] ? $stale : ['and', $stale, ['not in', 'id', $live]]);
    }

    private function save(ActiveRecord $record): void {
        if (!$record->save(false)) {
            throw new RuntimeException('Failed to persist an OAuth record');
        }
    }

    private function toClient(ClientRecord $record): OAuthClient {
        $uris = Json::decodeIfJson((string) $record->getAttribute('redirectUris'));

        return new OAuthClient(
            (string) $record->getAttribute('clientId'),
            (string) $record->getAttribute('name'),
            is_array($uris) ? array_values(array_filter($uris, is_string(...))) : [],
            (int) $record->getAttribute('id'),
            $this->date($record->getAttribute('dateCreated')),
            $this->date($record->getAttribute('lastUsedAt')),
        );
    }

    private function toGrant(GrantRecord $record): Grant {
        return new Grant(
            (int) $record->getAttribute('clientId'),
            (int) $record->getAttribute('userId'),
            Scope::from((string) $record->getAttribute('scope')),
            (int) $record->getAttribute('id'),
            $this->date($record->getAttribute('dateCreated')),
            $this->date($record->getAttribute('dateRevoked')),
        );
    }

    /**
     * Craft stores dates in UTC, and PHP's default timezone is the site's, so
     * a stored date read without a zone shifts by the site's offset: on a
     * UTC+2 site a 60-second code would expire two hours before it was made.
     */
    private function date(mixed $value): ?DateTimeImmutable {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value, new DateTimeZone('UTC')) : null;
    }
}
