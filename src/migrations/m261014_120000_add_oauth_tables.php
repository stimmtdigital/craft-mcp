<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\migrations;

use craft\db\Migration;
use craft\db\Table;

/**
 * Adds the OAuth connector's tables, and a nullable grantId on mcp_tokens so
 * the access tokens an app receives are deleted with its grant.
 *
 * Additive only: no existing table, column or row is altered, so tokens
 * minted before this migration keep working exactly as they did.
 *
 * @author stimmt.digital
 */
class m261014_120000_add_oauth_tables extends Migration {
    public function safeUp(): bool {
        $this->createClients();
        $this->createGrants();
        $this->createCodes();
        $this->createRefreshTokens();

        if ($this->db->getTableSchema('{{%mcp_tokens}}', true)?->getColumn('grantId') === null) {
            $this->addColumn('{{%mcp_tokens}}', 'grantId', (string) $this->integer()->null()->after('scope'));
            $this->createIndex(null, '{{%mcp_tokens}}', ['grantId'], false);
            $this->addForeignKey(null, '{{%mcp_tokens}}', ['grantId'], '{{%mcp_oauth_grants}}', ['id'], 'CASCADE');
        }

        return true;
    }

    public function safeDown(): bool {
        if ($this->db->getTableSchema('{{%mcp_tokens}}', true)?->getColumn('grantId') !== null) {
            // OAuth-issued tokens mean nothing without their grant; hand-minted
            // ones (grantId null) are untouched.
            $this->delete('{{%mcp_tokens}}', ['not', ['grantId' => null]]);
            $this->dropForeignKeyIfExists('{{%mcp_tokens}}', ['grantId']);
            $this->dropIndexIfExists('{{%mcp_tokens}}', ['grantId'], false);
            $this->dropColumn('{{%mcp_tokens}}', 'grantId');
        }

        $this->dropTableIfExists('{{%mcp_oauth_refresh_tokens}}');
        $this->dropTableIfExists('{{%mcp_oauth_codes}}');
        $this->dropTableIfExists('{{%mcp_oauth_grants}}');
        $this->dropTableIfExists('{{%mcp_oauth_clients}}');

        return true;
    }

    private function createClients(): void {
        if ($this->db->tableExists('{{%mcp_oauth_clients}}')) {
            return;
        }

        $this->createTable('{{%mcp_oauth_clients}}', [
            'id' => $this->primaryKey(),
            'clientId' => $this->string(64)->notNull(),
            'name' => $this->string(100)->notNull(),
            'redirectUris' => $this->text()->notNull(),
            'lastUsedAt' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%mcp_oauth_clients}}', ['clientId'], true);
    }

    private function createGrants(): void {
        if ($this->db->tableExists('{{%mcp_oauth_grants}}')) {
            return;
        }

        $this->createTable('{{%mcp_oauth_grants}}', [
            'id' => $this->primaryKey(),
            'clientId' => $this->integer()->notNull(),
            'userId' => $this->integer()->notNull(),
            'scope' => $this->string(16)->notNull(),
            'dateRevoked' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%mcp_oauth_grants}}', ['userId', 'dateRevoked'], false);
        $this->addForeignKey(null, '{{%mcp_oauth_grants}}', ['clientId'], '{{%mcp_oauth_clients}}', ['id'], 'CASCADE');
        $this->addForeignKey(null, '{{%mcp_oauth_grants}}', ['userId'], Table::USERS, ['id'], 'CASCADE');
    }

    private function createCodes(): void {
        if ($this->db->tableExists('{{%mcp_oauth_codes}}')) {
            return;
        }

        $this->createTable('{{%mcp_oauth_codes}}', [
            'id' => $this->primaryKey(),
            'codeHash' => $this->string(64)->notNull(),
            'grantId' => $this->integer()->notNull(),
            'redirectUri' => $this->text()->notNull(),
            'codeChallenge' => $this->string(128)->notNull(),
            'expiryDate' => $this->dateTime()->notNull(),
            'dateUsed' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%mcp_oauth_codes}}', ['codeHash'], true);
        $this->addForeignKey(null, '{{%mcp_oauth_codes}}', ['grantId'], '{{%mcp_oauth_grants}}', ['id'], 'CASCADE');
    }

    private function createRefreshTokens(): void {
        if ($this->db->tableExists('{{%mcp_oauth_refresh_tokens}}')) {
            return;
        }

        $this->createTable('{{%mcp_oauth_refresh_tokens}}', [
            'id' => $this->primaryKey(),
            'tokenHash' => $this->string(64)->notNull(),
            'grantId' => $this->integer()->notNull(),
            'expiryDate' => $this->dateTime()->notNull(),
            'dateUsed' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        $this->createIndex(null, '{{%mcp_oauth_refresh_tokens}}', ['tokenHash'], true);
        $this->addForeignKey(null, '{{%mcp_oauth_refresh_tokens}}', ['grantId'], '{{%mcp_oauth_grants}}', ['id'], 'CASCADE');
    }
}
