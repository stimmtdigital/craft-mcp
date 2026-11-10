<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Craft;
use stimmt\craft\Mcp\elements\PreviewUrl;
use stimmt\craft\Mcp\elements\Reader;
use stimmt\craft\Mcp\elements\refs\Keys;
use stimmt\craft\Mcp\elements\refs\Registry;
use stimmt\craft\Mcp\elements\refs\Translator;
use stimmt\craft\Mcp\elements\Writer;
use stimmt\craft\Mcp\events\RegisterFieldTranslatorsEvent;
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

        return new PreviewUrl(true, $settings->previewTokenDuration);
    }

    /**
     * Built once per request, with the field translators other plugins
     * register through Mcp::EVENT_REGISTER_FIELD_TRANSLATORS.
     */
    private static function translator(): Translator {
        if (Craft::$container->has(Translator::class)) {
            return Craft::$container->get(Translator::class);
        }

        $registry = new Registry();
        $translator = Translator::withDefaults(new Keys(), $registry);

        $event = new RegisterFieldTranslatorsEvent();
        Mcp::getInstance()?->trigger(Mcp::EVENT_REGISTER_FIELD_TRANSLATORS, $event);
        foreach ($event->translators as $fieldTranslator) {
            $registry->register($fieldTranslator);
        }

        Craft::$container->setSingleton(Translator::class, static fn (): Translator => $translator);

        return $translator;
    }
}
