<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\http\fetch;

use RuntimeException;

/**
 * A URL the server will not fetch, or a response it will not accept. The
 * message is meant for the caller: it says which rule refused and why.
 *
 * @author stimmt.digital
 */
final class FetchRefused extends RuntimeException {
}
