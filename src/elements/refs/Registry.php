<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\refs;

use craft\base\FieldInterface;

/**
 * Ordered translator registry; first match wins, later registrations are
 * prepended so custom translators override built-ins.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Registry {
    /** @var FieldTranslator[] */
    private array $translators = [];

    public function register(FieldTranslator $translator): void {
        array_unshift($this->translators, $translator);
    }

    public function for(FieldInterface $field): ?FieldTranslator {
        foreach ($this->translators as $translator) {
            if ($translator->handles($field)) {
                return $translator;
            }
        }

        return null;
    }
}
