<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements;

/**
 * Outcome of an element write.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Result {
    public const string ACTION_CREATED = 'created';

    public const string ACTION_UPDATED = 'updated';

    /**
     * @param int|null $draftElementId the draft's own element id, addressable by update/publish tools
     * @param Warning[] $warnings
     * @param array<string, string[]> $errors attribute or field path => messages
     */
    public function __construct(
        public string $action,
        public ?int $elementId,
        public ?int $draftId = null,
        public ?int $draftElementId = null,
        public ?WriteMode $state = null,
        public array $warnings = [],
        public array $errors = [],
        public ?string $cpEditUrl = null,
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
            'draftElementId' => $this->draftElementId,
            'state' => $this->state?->value,
            'cpEditUrl' => $this->cpEditUrl,
            'warnings' => array_map(static fn (Warning $w): array => $w->toArray(), $this->warnings),
            'errors' => $this->errors,
        ];
    }
}
