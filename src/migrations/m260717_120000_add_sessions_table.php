<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\migrations;

use craft\db\Migration;

/**
 * Adds the mcp_sessions table so HTTP transport sessions survive
 * load-balanced, multi-instance deployments.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class m260717_120000_add_sessions_table extends Migration {
    public function safeUp(): bool {
        if ($this->db->tableExists('{{%mcp_sessions}}')) {
            return true;
        }

        $this->createTable('{{%mcp_sessions}}', [
            'id' => $this->char(36)->notNull(),
            'data' => $this->longText()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->createIndex(null, '{{%mcp_sessions}}', ['dateUpdated'], false);

        return true;
    }

    public function safeDown(): bool {
        $this->dropTableIfExists('{{%mcp_sessions}}');

        return true;
    }
}
