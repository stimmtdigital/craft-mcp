<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements;

/**
 * One unresolved natural key, collected while translating a payload. Warnings
 * accompany read payloads and write results alike, failed writes included.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Warning {
    public function __construct(
        public string $field,
        public string $path,
        public array $key,
        public string $message,
    ) {
    }

    public function toArray(): array {
        return [
            'field' => $this->field,
            'path' => $this->path,
            'key' => $this->key,
            'message' => $this->message,
        ];
    }
}
