<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\psr;

use Craft;
use Psr\Container\ContainerInterface;

/**
 * PSR-11 adapter for Craft CMS / Yii2's service locator and container.
 *
 * This allows the MCP SDK to resolve dependencies through Craft's container system.
 * Craft::$container answers for anything that names a type; Craft::$app, whose
 * lookups are keyed by short component id, answers for everything else.
 */
class Container implements ContainerInterface {
    /**
     * Finds an entry of the container by its identifier and returns it.
     *
     * @param string $id Identifier of the entry to look for.
     * @return mixed Entry.
     * @throws ServiceNotFound If no entry was found.
     */
    public function get(string $id): mixed {
        if (Craft::$container->has($id)) {
            return Craft::$container->get($id);
        }

        if ($this->isComponentId($id)) {
            return Craft::$app->get($id);
        }

        throw new ServiceNotFound($id);
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     *
     * @param string $id Identifier of the entry to look for.
     */
    public function has(string $id): bool {
        if (Craft::$container->has($id)) {
            return true;
        }

        return $this->isComponentId($id);
    }

    /**
     * A name that resolves to a class or interface is a type to build, never a
     * component to look up.
     *
     * WHY: the SDK asks this container for a handler's own class name and takes
     * whatever it gets back (ReferenceHandler::getClassInstance), falling back to
     * plain instantiation when the answer is no. Craft's service locator is keyed
     * by short ids ('db', 'cache', 'plugins'), and answering yes for a class
     * whose name happens to match one would hand the SDK that component in place
     * of the tool it asked for. Craft::$container is the half that is keyed by
     * type, so types are answered there or not at all.
     */
    private function isComponentId(string $id): bool {
        return !class_exists($id) && !interface_exists($id) && Craft::$app->has($id);
    }
}
