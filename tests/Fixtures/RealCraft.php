<?php

declare(strict_types=1);

/**
 * Loads the real Yii and Craft base classes for unit tests whose code paths
 * need them (ToolRegistry initialization, Mcp::isToolEnabled(), logging).
 *
 * Coexistence contract with CraftStub.php: whichever file loads first defines
 * `class Craft`; both guard with class_exists, so suite order can never fatal
 * on a redeclare. Tests requiring this file must pass against either Craft
 * (the stub carries no-op logging statics for exactly that reason).
 */
require_once dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';

if (!class_exists('Craft', false)) {
    require dirname(__DIR__, 2) . '/vendor/craftcms/cms/src/Craft.php';
}
