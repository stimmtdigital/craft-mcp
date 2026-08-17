<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Craft;
use Psr\Log\LoggerInterface;
use stimmt\craft\Mcp\elements\Reader;
use stimmt\craft\Mcp\elements\refs\Keys;
use stimmt\craft\Mcp\elements\refs\Registry;
use stimmt\craft\Mcp\elements\refs\Translator;
use stimmt\craft\Mcp\elements\Writer;
use stimmt\craft\Mcp\events\RegisterFieldTranslatorsEvent;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\tools\EntryTools;
use stimmt\craft\Mcp\tools\EntryWorkflowTools;
use stimmt\craft\Mcp\tools\NestedEntryTools;
use stimmt\craft\Mcp\tools\TinkerTools;

/**
 * Everything a tool call needs in the container, wired the same way on every
 * transport.
 *
 * WHY: this lived in `bin/mcp-server` alone, so the HTTP endpoint registered a
 * logger and nothing else. The SDK resolves a tool class through the container
 * when it knows it and plainly instantiates it otherwise, so over HTTP the
 * fallback fired: `tinker` was built with a NullLogger and wrote nothing, and
 * `ElementModule` found no translator and fell back to the defaults, which
 * means a plugin registering a custom field translator through
 * EVENT_REGISTER_FIELD_TRANSLATORS saw its field types translated on stdio and
 * raw over HTTP. The same entry, read through two transports, came back
 * differently.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Runtime {
    /**
     * Idempotent: the HTTP path runs it per request, and re-registering a
     * definition on Craft's container is a plain overwrite.
     */
    public static function bootstrap(LoggerInterface $logger): void {
        Craft::$container->setSingleton(LoggerInterface::class, fn (): LoggerInterface => $logger);

        $translator = self::translator();
        Craft::$container->setSingleton(Translator::class, fn (): Translator => $translator);
        Craft::$container->setSingleton(Reader::class, fn (): Reader => new Reader($translator));
        Craft::$container->setSingleton(Writer::class, fn (): Writer => new Writer($translator));

        // Named so the SDK's container lookup finds them and injects, rather
        // than reaching its bare-instantiation fallback and silently skipping
        // every dependency these classes declare.
        foreach ([TinkerTools::class, EntryTools::class, EntryWorkflowTools::class, NestedEntryTools::class] as $tool) {
            Craft::$container->set($tool);
        }
    }

    /**
     * The shared translator registry, with whatever other plugins add to it.
     */
    private static function translator(): Translator {
        $registry = new Registry();
        $translator = Translator::withDefaults(new Keys(), $registry);

        $event = new RegisterFieldTranslatorsEvent();
        Mcp::getInstance()?->trigger(Mcp::EVENT_REGISTER_FIELD_TRANSLATORS, $event);

        foreach ($event->translators as $fieldTranslator) {
            $registry->register($fieldTranslator);
        }

        return $translator;
    }
}
