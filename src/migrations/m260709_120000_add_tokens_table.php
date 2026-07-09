<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Adds the mcp_tokens table for HTTP transport bearer tokens.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class m260709_120000_add_tokens_table extends Migration {
    public function safeUp(): bool {
        if ($this->db->tableExists('{{%mcp_tokens}}')) {
            return true;
        }

        $this->createTable('{{%mcp_tokens}}', [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'tokenHash' => $this->string(64)->notNull(),
            'userId' => $this->integer()->notNull(),
            'scope' => $this->string(16)->notNull(),
            'expiryDate' => $this->dateTime()->null(),
            'lastUsedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, '{{%mcp_tokens}}', ['tokenHash'], true);
        $this->createIndex(null, '{{%mcp_tokens}}', ['name'], false);
        $this->addForeignKey(null, '{{%mcp_tokens}}', ['userId'], Table::USERS, ['id'], 'CASCADE');

        return true;
    }

    public function safeDown(): bool {
        $this->dropTableIfExists('{{%mcp_tokens}}');

        return true;
    }
}
