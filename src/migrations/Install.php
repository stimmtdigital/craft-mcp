<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\migrations;

use craft\db\Migration;

/**
 * Since 2.0 the plugin keeps no tables of its own: Craft's MCP server holds
 * the connections and tokens. Installing gives Craft the OAuth signing keys
 * that server needs; uninstalling removes what a 1.x install left behind and
 * leaves the keys, which Craft's other OAuth clients may rely on.
 */
class Install extends Migration {
    public function safeUp(): bool {
        return (new m261111_000000_oauth_signing_keys())->safeUp();
    }

    public function safeDown(): bool {
        return (new m261110_000000_remove_v1_tables())->safeUp();
    }
}
