<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\enums;

/**
 * Output modes for tinker tool.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
enum OutputMode: string {
    case DUMP = 'dump';
    case JSON = 'json';
    case RAW = 'raw';
    case PRINT_R = 'print_r';
}
