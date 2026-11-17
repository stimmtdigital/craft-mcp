<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\capabilities;

use Craft;
use craft\mcp\attributes\RequiresAdmin;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use stimmt\craft\Mcp\insight\Advisories;
use stimmt\craft\Mcp\insight\ComposerLock;
use stimmt\craft\Mcp\insight\PackagistAdvisories;
use stimmt\craft\Mcp\support\Budget;
use stimmt\craft\Mcp\support\Response;

/**
 * Known security advisories for the Composer packages the project installed.
 */
final class DependencyTools {
    public const string SOURCE = 'packagist';

    #[McpTool(
        name: 'stimmt_dependencies_audit',
        title: 'Security advisories',
        description: 'List known security advisories that affect the Composer packages this project has installed, like composer audit. Reads installed versions from the project\'s composer.lock and asks packagist.org\'s public security-advisory API about them: this SENDS THE LIST OF INSTALLED PACKAGE NAMES (not their versions) to packagist.org; the version matching happens on this server. Packages Packagist has no data on (private packages, forks) are listed under "uncovered" because they were not checked, not because they are safe. Fails, rather than answering empty, when packagist.org cannot be reached.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true, openWorldHint: true),
    )]
    #[RequiresAdmin]
    public function auditDependencies(
        #[Schema(description: 'Also check the packages installed for development only (packages-dev in composer.lock). Defaults to false, the packages a production install runs.')]
        bool $includeDev = false,
    ): array {
        $installed = ComposerLock::packages($this->lockFile(), $includeDev);
        $packagist = PackagistAdvisories::overHttp(Craft::createGuzzleClient());
        $result = Advisories::match($installed, $packagist->forPackages(array_keys($installed)));

        return Budget::assert(Response::list('advisories', $result['advisories'], [
            'checked' => count($installed),
            'source' => self::SOURCE,
            'includeDev' => $includeDev,
            'uncovered' => $result['uncovered'],
            // Advisories whose range or installed version Composer's parser
            // could not read: neither affected nor clear, so named apart.
            'undecided' => $result['undecided'],
        ]));
    }

    private function lockFile(): string {
        $path = Craft::getAlias('@root') . '/composer.lock';
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new ToolCallException(
                "No readable composer.lock at {$path}. Run composer install in the project root to create one.",
            );
        }

        return $contents;
    }
}
