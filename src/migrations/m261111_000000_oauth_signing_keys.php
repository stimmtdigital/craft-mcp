<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\migrations;

use craft\db\Migration;
use stimmt\craft\Mcp\integration\SigningKeys;

/**
 * Gives Craft the OAuth signing keys its MCP server needs, so connecting a
 * client does not depend on someone running `php craft oauth/generate-keys`.
 *
 * WHY it never fails: a missing key pair breaks MCP logins, not the site, and
 * a deploy that stopped here would take the whole release down for it. The
 * outcome is printed instead, and `php craft mcp/check` keeps reporting it.
 */
class m261111_000000_oauth_signing_keys extends Migration {
    public function safeUp(): bool {
        echo '    > ' . SigningKeys::describe(SigningKeys::forCraft()->ensure()) . "\n";

        return true;
    }

    public function safeDown(): bool {
        // The keys stay: removing them would log out every MCP client.
        return true;
    }
}
