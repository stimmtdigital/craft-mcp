<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\events;

use stimmt\craft\Mcp\elements\refs\FieldTranslator;
use yii\base\Event;

/**
 * Fired at server boot so plugins can register translators for field types
 * that embed element ids outside BaseRelationField. Registered translators
 * win over the built-ins.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class RegisterFieldTranslatorsEvent extends Event {
    /** @var FieldTranslator[] */
    public array $translators = [];
}
