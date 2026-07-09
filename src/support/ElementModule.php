<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Craft;
use stimmt\craft\Mcp\elements\Reader;
use stimmt\craft\Mcp\elements\refs\Translator;
use stimmt\craft\Mcp\elements\Writer;

/**
 * Shared construction for the elements-module collaborators used by tools.
 * Consults the Craft DI container for a Translator singleton so plugins can
 * swap it, falling back to the default translator set.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class ElementModule {
    public static function reader(): Reader {
        return new Reader(self::translator());
    }

    public static function writer(): Writer {
        return new Writer(self::translator());
    }

    private static function translator(): Translator {
        return Craft::$container->has(Translator::class)
            ? Craft::$container->get(Translator::class)
            : Translator::withDefaults();
    }
}
