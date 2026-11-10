<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\integration;

enum Level: string {
    case Ok = 'ok';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
