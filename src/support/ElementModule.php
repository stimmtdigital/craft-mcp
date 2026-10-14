<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Craft;
use stimmt\craft\Mcp\elements\PreviewUrl;
use stimmt\craft\Mcp\elements\Reader;
use stimmt\craft\Mcp\elements\refs\Translator;
use stimmt\craft\Mcp\elements\Writer;
use stimmt\craft\Mcp\Mcp;

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
        return new Writer(self::translator(), self::preview());
    }

    /**
     * Preview links as the install's settings configure them.
     */
    public static function preview(): PreviewUrl {
        $settings = Mcp::settings();

        return new PreviewUrl($settings->previewUrls, $settings->previewTokenDuration);
    }

    private static function translator(): Translator {
        return Craft::$container->has(Translator::class)
            ? Craft::$container->get(Translator::class)
            : Translator::withDefaults();
    }
}
