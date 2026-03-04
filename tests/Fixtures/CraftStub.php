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
    }
}
