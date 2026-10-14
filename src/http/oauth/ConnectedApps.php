<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http\oauth;

/**
 * The connected-apps table's rows: each live grant with its app's name.
 *
 * @author stimmt.digital
 */
final readonly class ConnectedApps {
    public function __construct(private OAuthStore $store) {
    }

    /**
     * @return list<array{id: int, userId: int, name: string, scope: string, connected: ?int, lastUsed: ?int}>
     */
    public function rows(?int $userId = null): array {
        $rows = [];
        foreach ($this->store->liveGrants($userId) as $grant) {
            $client = $this->store->findClientById($grant->clientRecordId);
            $rows[] = [
                'id' => (int) $grant->id,
                'userId' => $grant->userId,
                'name' => $client->name ?? 'MCP client',
                'scope' => $grant->scope->value,
                'connected' => $grant->dateCreated?->getTimestamp(),
                'lastUsed' => $client?->lastUsedAt?->getTimestamp(),
            ];
        }

        return $rows;
    }
}
