<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\enums;

/**
 * Paid-feature tiers for the MCP plugin, mapped onto Craft's native plugin
 * editions. Ordered lowest to highest; the order drives Mcp::editions() and
 * the atLeast() comparison.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
enum Edition: string {
    case Standard = 'standard';
    case Pro = 'pro';

    /**
     * Call to action shared by the upgrade message and the Standard-edition
     * instructions note.
     */
    public const UPGRADE_CTA = 'Upgrade in the Craft control panel under Settings > Plugins.';

    /**
     * Edition handles ordered lowest to highest, for Craft's editions() list.
     *
     * @return string[]
     */
    public static function ordered(): array {
        return array_map(static fn (self $edition): string => $edition->value, self::cases());
    }

    /**
     * Resolve a stored edition handle, falling back to Standard for unknown or
     * empty values.
     */
    public static function fromHandle(?string $handle): self {
        if ($handle === null) {
            return self::Standard;
        }

        return self::tryFrom($handle) ?? self::Standard;
    }

    /**
     * True when this edition is at least as high as $other in the ordering.
     */
    public function atLeast(self $other): bool {
        $order = self::ordered();

        return array_search($this->value, $order, true) >= array_search($other->value, $order, true);
    }

    /**
     * The single upgrade message surfaced when a Pro tool is used on a lower
     * edition (visible-but-locked mode).
     */
    public static function proUpgradeMessage(): string {
        return 'This tool requires the Pro edition of the Craft MCP plugin. '
            . 'The current edition does not include content-writing tools. '
            . self::UPGRADE_CTA;
    }
}
