<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use craft\console\controllers\HelpController;
use Override;
use yii\base\InvalidArgumentException;
use yii\base\Module;

/**
 * Craft's command listing, tolerant of a module it cannot read.
 *
 * WHY: Yii walks every module's controller directory, resolving each
 * module's controller namespace as a path alias. A module that defines that
 * alias only for console requests (the headless template's requestid module
 * does) throws during an HTTP request, and the walk took every other
 * command down with it. Here that module contributes no commands and is
 * named in skipped(), so the caller learns the list is partial and why,
 * rather than getting nothing at all.
 */
final class ConsoleCommands extends HelpController {
    /** @var string[] */
    private array $skipped = [];

    /**
     * @return string[] unique ids of modules whose commands could not be listed
     */
    public function skipped(): array {
        return $this->skipped;
    }

    /**
     * @param Module $module
     * @return string[]
     */
    #[Override]
    protected function getModuleCommands($module): array {
        try {
            return parent::getModuleCommands($module);
        } catch (InvalidArgumentException) {
            $this->skipped[] = $module->getUniqueId();

            return [];
        }
    }
}
