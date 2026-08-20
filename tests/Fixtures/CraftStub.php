<?php

declare(strict_types=1);

/**
 * Minimal Craft stub for unit tests that don't bootstrap the full application.
 *
 * Only loaded if the real Craft class isn't available (i.e. outside Craft runtime).
 */
if (!class_exists('Craft', false)) {
    class Craft {
        public static ?object $app = null;

        /**
         * The real Craft inherits this from BaseYii, where Yii.php fills it in.
         * Declared here so a test that exercises container lookups can assign a
         * real yii\di\Container whichever of the two Craft classes won the race
         * to be defined; without the declaration the assignment is a fatal
         * "undeclared static property" and the test becomes suite-order
         * dependent.
         */
        public static ?object $container = null;

        /** No-op logging statics so code paths that log via Craft::info()/warning()/error() run under the stub. */
        public static function info(mixed $message, string $category = 'application'): void {
        }

        public static function warning(mixed $message, string $category = 'application'): void {
        }

        public static function error(mixed $message, string $category = 'application'): void {
        }
    }
}
