<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\operations;

use stimmt\craft\Mcp\integration\Check;

/**
 * One line of the install health report: the plugin's Check, filed under the
 * area of the install it describes.
 *
 * WHY Check is wrapped rather than given an area of its own: Check is what the
 * settings screen and `craft mcp/check` already render, and those have no use
 * for an area. Reusing it keeps one shape for "what was checked, how it stands,
 * what to do", whichever surface shows it.
 */
final readonly class HealthCheck {
    public function __construct(
        public string $area,
        public Check $check,
    ) {
    }

    /**
     * @return array{area: string, label: string, level: string, message: string, fix?: string}
     */
    public function toArray(): array {
        $row = [
            'area' => $this->area,
            'label' => $this->check->label,
            'level' => $this->check->level->value,
            'message' => $this->check->message,
        ];

        // Absent rather than null: a fix only exists where something needs doing.
        if ($this->check->fix !== null) {
            $row['fix'] = $this->check->fix;
        }

        return $row;
    }
}
