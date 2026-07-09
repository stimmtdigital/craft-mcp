<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http;

use InvalidArgumentException;
use stimmt\craft\Mcp\enums\ToolCategory;

/**
 * Authorization scope carried by an HTTP token. The predicate derives from
 * tool metadata (category + dangerous flag), never from tool-name lists, so
 * future tools sort themselves automatically.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
enum Scope: string {
    case ReadOnly = 'readonly';
    case Content = 'content';
    case Full = 'full';

    public function allows(string $category, bool $dangerous): bool {
        return match ($this) {
            self::Full => true,
            self::Content => !$dangerous || $category === ToolCategory::CONTENT->value,
            self::ReadOnly => !$dangerous,
        };
    }

    public static function fromInput(string $value): self {
        $scope = self::tryFrom(strtolower(trim($value)));
        if ($scope === null) {
            $valid = implode(', ', array_column(self::cases(), 'value'));

            throw new InvalidArgumentException("Unknown scope '{$value}'; use one of: {$valid}");
        }

        return $scope;
    }
}
