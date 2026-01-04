<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Craft;
use Psr\Container\ContainerInterface;

/**
 * PSR-11 adapter for Craft CMS / Yii2's service locator and container.
 *
 * This allows the MCP SDK to resolve dependencies through Craft's container system.
 * Yii's service locator (Craft::$app) takes precedence over the DI container.
 */
class Psr11ContainerAdapter implements ContainerInterface {
    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @param string $id Identifier of the entry to look for.
     * @return mixed Entry.
     * @throws ServiceNotFoundException If no entry was found.
     */
    public function get(string $id): mixed {
        if ($this->has($id)) {
            // Yii's service locator takes precedence
            if (Craft::$app->has($id)) {
                return Craft::$app->get($id);
            }

            return Craft::$container->get($id);
        }

        throw new ServiceNotFoundException($id);
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     *
     * @param string $id Identifier of the entry to look for.
     */
    public function has(string $id): bool {
        if (Craft::$app->has($id)) {
            return true;
        }

        return (bool) Craft::$container->has($id);
    }
}
