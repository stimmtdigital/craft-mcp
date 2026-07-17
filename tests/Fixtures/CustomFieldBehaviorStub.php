<?php

declare(strict_types=1);

namespace craft\behaviors;

/**
 * Minimal CustomFieldBehavior stub for unit tests that don't bootstrap the
 * full Craft application. The real class only exists as a template
 * (src/behaviors/CustomFieldBehavior.php.template) that Craft::autoload()
 * compiles into memory once Craft::$app is fully booted and installed; unit
 * tests never boot Craft, so the real class is never generated. They only
 * need the public static $fieldHandles map that
 * ConfigFreshness::patchHandles() writes to.
 *
 * Only loaded if the real, Craft-generated class isn't available.
 */
if (!class_exists('craft\\behaviors\\CustomFieldBehavior', false)) {
    class CustomFieldBehavior {
        /** @var array<string, bool> */
        public static array $fieldHandles = [];
    }
}
