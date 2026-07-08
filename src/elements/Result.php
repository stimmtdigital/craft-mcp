<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements;

/**
 * Outcome of an element write.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Result {
    public const ACTION_CREATED = 'created';

    public const ACTION_UPDATED = 'updated';

    /**
     * @param Warning[] $warnings
     * @param array<string, string[]> $errors attribute or field path => messages
     */
    public function __construct(
        public readonly string $action,
        public readonly ?int $elementId,
        public readonly ?int $draftId = null,
        public readonly ?WriteMode $state = null,
        public readonly array $warnings = [],
        public readonly array $errors = [],
        public readonly ?string $cpEditUrl = null,
    ) {
    }

    public function isFailure(): bool {
        return $this->elementId === null || $this->errors !== [];
    }

    public function toArray(): array {
        return [
            'action' => $this->action,
            'elementId' => $this->elementId,
            'draftId' => $this->draftId,
            'state' => $this->state?->value,
            'cpEditUrl' => $this->cpEditUrl,
            'warnings' => array_map(static fn (Warning $w): array => $w->toArray(), $this->warnings),
            'errors' => $this->errors,
        ];
    }
}
