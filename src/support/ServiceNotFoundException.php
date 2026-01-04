<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

/**
 * Exception thrown when a service is not found in the container.
 * Implements PSR-11's NotFoundExceptionInterface for proper container compliance.
 */
class ServiceNotFoundException extends RuntimeException implements NotFoundExceptionInterface {
    public function __construct(string $id) {
        parent::__construct("Service not found: {$id}");
    }
}
