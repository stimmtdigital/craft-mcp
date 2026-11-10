<?php

declare(strict_types=1);

/**
 * The harness's hands inside the Craft install. Never run from the harness's
 * side: Install pipes this file into `php` in the Craft root, so nothing has
 * to be copied into the project and nothing it prints ever touches a disk.
 *
 *   php -- info                     admin email, MCP endpoint, editions
 *   php -- mint <name> <scope,...>  a personal access token for the admin
 *   php -- revoke <name>            revoke and remove the tokens named <name>
 *
 * Answers one JSON object on stdout. A minted token is in that answer and
 * nowhere else: the caller keeps it in memory and never prints it.
 *
 * WHY revoke AND delete: Craft revokes by flagging the row, so revoked rows
 * would pile up three per run. The delete is scoped to the exact name this
 * run minted, which carries the run id, so it can only ever reach a token the
 * same run created.
 */

use craft\db\Query;
use craft\db\Table;
use craft\elements\User;
use craft\oauth\Repositories;

$root = getcwd();
if ($root === false || !is_file($root . '/bootstrap.php')) {
    fwrite(STDERR, "Run in the Craft root (no bootstrap.php in the working directory).\n");
    exit(1);
}

require $root . '/bootstrap.php';
require $root . '/vendor/craftcms/cms/bootstrap/console.php';

$command = $argv[1] ?? '';

$admin = static function (): User {
    $user = User::find()->admin(true)->status('active')->orderBy(['id' => SORT_ASC])->one();
    if (!$user instanceof User) {
        throw new RuntimeException('This install has no active admin.');
    }

    return $user;
};

$answer = static function (array $payload): never {
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
};

try {
    if ($command === 'info') {
        $mcp = Craft::$app->get('mcp');
        $settings = $mcp->getSettings();
        $plugin = Craft::$app->getPlugins()->getPlugin('mcp');

        $answer([
            'admin' => $admin()->email,
            'enabled' => $settings->enabled,
            'endpoint' => $settings->url($settings->route),
            'craftVersion' => Craft::$app->getVersion(),
            'craftEdition' => Craft::$app->edition->name,
            'pluginEdition' => $plugin?->edition,
            'primarySite' => Craft::$app->getSites()->getPrimarySite()->handle,
        ]);
    }

    if ($command === 'mint') {
        $name = (string) ($argv[2] ?? '');
        $scopes = array_values(array_filter(explode(',', (string) ($argv[3] ?? ''))));
        if (!str_starts_with($name, 'smoke-') || $scopes === []) {
            throw new RuntimeException('mint needs a name starting smoke- and at least one scope.');
        }

        $token = Craft::$app->getOAuth()->createPersonalAccessToken($admin(), $name, $scopes, new DateInterval('PT1H'));
        $answer(['name' => $name, 'scopes' => $scopes, 'token' => $token]);
    }

    if ($command === 'revoke') {
        $name = (string) ($argv[2] ?? '');
        if (!str_starts_with($name, 'smoke-')) {
            throw new RuntimeException('revoke only touches tokens named smoke-...');
        }

        $ids = (new Query())->select(['id'])->from(Table::OAUTH_ACCESS_TOKENS)->where(['name' => $name])->column();
        $repository = new Repositories(Craft::$app->getOAuth()->getResource('mcp'));
        foreach ($ids as $id) {
            $repository->revokeAccessToken((string) $id);
        }

        $deleted = Craft::$app->getDb()->createCommand()
            ->delete(Table::OAUTH_ACCESS_TOKENS, ['name' => $name, 'revoked' => true])
            ->execute();

        $answer(['name' => $name, 'revoked' => count($ids), 'deleted' => $deleted]);
    }

    throw new RuntimeException("Unknown command '{$command}'. Known: info, mint, revoke.");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
