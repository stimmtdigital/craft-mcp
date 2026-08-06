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

        /** No-op logging statics so code paths that log via Craft::info()/warning()/error() run under the stub. */
        public static function info(mixed $message, string $category = 'application'): void {
        }

        public static function warning(mixed $message, string $category = 'application'): void {
        }

        public static function error(mixed $message, string $category = 'application'): void {
        }
    }
}
