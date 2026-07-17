<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\migrations;

use craft\db\Migration;

/**
 * Fresh-install migration; delegates to the same table definition the
 * incremental migration creates, so the two can never diverge.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class Install extends Migration {
    public function safeUp(): bool {
        return (new m260709_120000_add_tokens_table())->safeUp()
            && (new m260717_120000_add_sessions_table())->safeUp();
    }

    public function safeDown(): bool {
        return (new m260717_120000_add_sessions_table())->safeDown()
            && (new m260709_120000_add_tokens_table())->safeDown();
    }
}
