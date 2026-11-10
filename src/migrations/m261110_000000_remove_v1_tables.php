<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\migrations;

use craft\db\Migration;

/**
 * Drops the tables 1.x kept for its own MCP server: static tokens, HTTP
 * sessions and the OAuth connector.
 *
 * WHY they go rather than migrate: since 2.0, MCP clients connect to Craft's
 * own server, which issues its own OAuth tokens and does not accept the 1.x
 * `mcp_` tokens. Nothing can use these rows any more, and the UPGRADE guide
 * tells site owners to reconnect their clients through Craft. Children are
 * dropped before the tables their foreign keys point at.
 */
class m261110_000000_remove_v1_tables extends Migration {
    private const array TABLES = [
        '{{%mcp_oauth_refresh_tokens}}',
        '{{%mcp_oauth_codes}}',
        '{{%mcp_tokens}}',
        '{{%mcp_oauth_grants}}',
        '{{%mcp_oauth_clients}}',
        '{{%mcp_sessions}}',
    ];

    public function safeUp(): bool {
        foreach (self::TABLES as $table) {
            $this->dropTableIfExists($table);
        }

        return true;
    }

    public function safeDown(): bool {
        echo "m261110_000000_remove_v1_tables cannot be reverted: 1.x tokens are not recoverable.\n";

        return false;
    }
}
