<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\records;

use craft\db\ActiveRecord;
use Override;

/**
 * ActiveRecord for the mcp_oauth_clients table.
 *
 * @author stimmt.digital
 */
class OAuthClient extends ActiveRecord {
    #[Override]
    public static function tableName(): string {
        return '{{%mcp_oauth_clients}}';
    }
}
