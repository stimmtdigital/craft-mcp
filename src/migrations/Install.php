<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\migrations;

use craft\db\Migration;

/**
 * Since 2.0 the plugin keeps no tables of its own: Craft's MCP server holds
 * the connections and tokens. Uninstalling still removes what a 1.x install
 * left behind.
 */
class Install extends Migration {
    public function safeUp(): bool {
        return true;
    }

    public function safeDown(): bool {
        return (new m261110_000000_remove_v1_tables())->safeUp();
    }
}
