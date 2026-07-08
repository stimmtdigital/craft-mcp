<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements;

/**
 * One unresolved natural key, attached to a successful write.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Warning {
    public function __construct(
        public readonly string $field,
        public readonly string $path,
        public readonly array $key,
        public readonly string $message,
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
