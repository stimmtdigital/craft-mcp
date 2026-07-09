<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\refs;

use craft\base\FieldInterface;
use stimmt\craft\Mcp\elements\Context;

/**
 * Strategy for one field type whose serialized value embeds element ids.
 * Values of unmatched fields pass through untouched.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
interface FieldTranslator {
    public function handles(FieldInterface $field): bool;

    public function toKeys(FieldInterface $field, mixed $value, Context $context): mixed;

    public function toIds(FieldInterface $field, mixed $value, Context $context): mixed;
}
