<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use stimmt\craft\Mcp\integration\Level;
use stimmt\craft\Mcp\integration\Status;
use yii\console\ExitCode;

/**
 * Reports whether Craft's MCP server and this plugin work together.
 *
 *   php craft mcp/check
 *
 * Exits non-zero when a check fails, so a deploy script can stop on it.
 */
class CheckController extends Controller {
    public function actionIndex(): int {
        $failed = false;

        foreach (Status::run() as $check) {
            $color = match ($check->level) {
                Level::Ok => Console::FG_GREEN,
                Level::Info => Console::FG_CYAN,
                Level::Warning => Console::FG_YELLOW,
                Level::Error => Console::FG_RED,
            };
            $this->stdout(str_pad($check->level->value, 8), $color);
            $this->stdout("{$check->label}: {$check->message}\n");
            if ($check->fix !== null) {
                $this->stdout(str_repeat(' ', 8) . "Fix: {$check->fix}\n");
            }
            $failed = $failed || $check->level === Level::Error;
        }

        return $failed ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
