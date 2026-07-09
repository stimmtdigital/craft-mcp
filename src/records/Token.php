<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\records;

use craft\db\ActiveRecord;

/**
 * ActiveRecord for the mcp_tokens table.
 *
 * @property int $id
 * @property string $name
 * @property string $tokenHash
 * @property int $userId
 * @property string $scope
 * @property string|null $expiryDate
 * @property string|null $lastUsedAt
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class Token extends ActiveRecord {
    public static function tableName(): string {
        return '{{%mcp_tokens}}';
    }
}
