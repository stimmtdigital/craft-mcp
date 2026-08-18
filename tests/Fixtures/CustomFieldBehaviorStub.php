<?php

declare(strict_types=1);

namespace craft\behaviors;

use yii\base\Behavior;

/**
 * Minimal CustomFieldBehavior stub for unit tests that don't bootstrap the
 * full Craft application. The real class only exists as a template
 * (src/behaviors/CustomFieldBehavior.php.template) that Craft::autoload()
 * compiles into memory once Craft::$app is fully booted and installed; unit
 * tests never boot Craft, so the real class is never generated.
 *
 * ConfigFreshness::patchHandles() only needs the public static $fieldHandles
 * map. Element construction (NestedPositionTest and friends build real
 * Entry/GlobalSet instances) additionally needs the stub to be an attachable
 * yii Behavior carrying the canSetProperties/hasMethods flags and magic
 * field-value storage the real template declares, because Element::init()
 * attaches and configures the 'customFields' behavior unconditionally.
 *
 * Only loaded if the real, Craft-generated class isn't available.
 */
if (!class_exists('craft\\behaviors\\CustomFieldBehavior', false)) {
    class CustomFieldBehavior extends Behavior {
        /** @var array<string, bool> */
        public static array $fieldHandles = [];

        public bool $canSetProperties = true;

        public bool $hasMethods = false;

        /** @var array<string, mixed> */
        private array $values = [];

        public function __get($name): mixed {
            return $this->values[$name] ?? null;
        }

        public function __set($name, $value): void {
            $this->values[$name] = $value;
        }

        public function __isset($name): bool {
            return isset($this->values[$name]);
        }
    }
}
