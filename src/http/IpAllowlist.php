<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http;

use yii\helpers\IpHelper;

/**
 * The allowedIps setting: exact addresses and CIDR ranges, IPv4 and IPv6.
 *
 * WHY ranges: the Claude connector reaches a site from Anthropic's
 * 160.79.104.0/21, and an install that keeps the list filled could only admit
 * it by emptying the list. An empty list still admits everyone; an entry or a
 * client address that does not parse admits no one, so a typo fails closed.
 *
 * @author stimmt.digital
 */
final readonly class IpAllowlist {
    /**
     * @param string[] $entries
     */
    public function __construct(private array $entries) {
    }

    public function allows(?string $ip): bool {
        if ($this->entries === []) {
            return true;
        }

        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return array_any($this->entries, fn ($entry) => $this->valid($entry) && IpHelper::inRange($ip, $entry));
    }

    private function valid(string $entry): bool {
        [$address, $mask] = array_pad(explode('/', $entry, 2), 2, null);
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($mask === null) {
            return true;
        }

        return ctype_digit($mask) && (int) $mask <= (str_contains($address, ':') ? 128 : 32);
    }
}
