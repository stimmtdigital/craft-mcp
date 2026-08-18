<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Server\Protocol;
use Mcp\Server\Resource\SubscriptionManagerInterface;
use Mcp\Server\Session\SessionInterface;

/**
 * Resource subscriptions that also fire for the template a client subscribed to.
 *
 * WHY: resources/subscribe accepts craft://entries/{section}/{slug}. The
 * registry matches a template by regex, and the literal braces are not slashes,
 * so the template string matches its own pattern and the subscription is stored
 * under it verbatim. Every URI we ever notify is concrete, and the SDK's manager
 * compares subscription keys for equality, so a client that subscribed to the
 * template it had just read from resources/templates/list was told yes and then
 * heard nothing, forever. That subscription means "tell me about any entry",
 * which is worth honouring rather than turning into an error.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final readonly class Subscription implements SubscriptionManagerInterface {
    /**
     * Our own session key. The SDK's manager writes 'resource_subscriptions' as
     * an inline literal at five sites with no constant to import, and owning the
     * manager is what makes the key ours to name instead of duplicate. Namespaced
     * under 'craft' so it cannot be mistaken for, or collide with, the SDK's own
     * '_mcp.*' keys.
     */
    private const string KEY = 'craft.subscriptions';

    public function subscribe(SessionInterface $session, string $uri): void {
        $subscriptions = $this->all($session);
        $subscriptions[$uri] = true;

        $this->put($session, $subscriptions);
    }

    public function unsubscribe(SessionInterface $session, string $uri): void {
        $subscriptions = $this->all($session);
        unset($subscriptions[$uri]);

        $this->put($session, $subscriptions);
    }

    public function isSubscribed(SessionInterface $session, string $uri): bool {
        $subscriptions = $this->all($session);
        if (isset($subscriptions[$uri])) {
            return true;
        }

        return array_any(array_keys($subscriptions), fn ($key) => $this->covers((string) $key, $uri));
    }

    /**
     * Honoured for a caller that holds a Protocol; nothing in this plugin does.
     * The SDK builds one privately and hands it to a final Server, so our own
     * notifier reaches the client through the request's gateway instead. Left
     * faithful rather than empty, because an interface method that silently does
     * nothing is a trap for whoever wires this manager somewhere else.
     */
    public function notifyResourceChanged(Protocol $protocol, SessionInterface $session, string $uri): void {
        if (!$this->isSubscribed($session, $uri)) {
            return;
        }

        $protocol->sendNotification(new ResourceUpdatedNotification($uri), $session);
    }

    /**
     * Whether a stored subscription key covers a concrete URI.
     *
     * Mirrors how the registry compiles a template
     * (ResourceTemplateReference::compileTemplate, which is private): a {name}
     * placeholder stands for exactly one path segment, and everything around it
     * is literal. Only stored keys that carry a placeholder are candidates, so an
     * ordinary URI costs one str_contains.
     */
    private function covers(string $key, string $uri): bool {
        if (!str_contains($key, '{')) {
            return false;
        }

        $segments = preg_split('/(\{\w+\})/', $key, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($segments === false) {
            return false;
        }

        $pattern = '';
        foreach ($segments as $segment) {
            $pattern .= preg_match('/^\{\w+\}$/', $segment) === 1 ? '[^/]+' : preg_quote($segment, '#');
        }

        return preg_match('#^' . $pattern . '$#', $uri) === 1;
    }

    /**
     * The subscription set as the session holds it: URI keys, true values. Typed
     * as it comes back, because a session payload is whatever was decoded from
     * the store and this is the place that has to cope with that.
     */
    private function all(SessionInterface $session): array {
        $subscriptions = $session->get(self::KEY, []);

        return is_array($subscriptions) ? $subscriptions : [];
    }

    private function put(SessionInterface $session, array $subscriptions): void {
        $session->set(self::KEY, $subscriptions);
        $session->save();
    }
}
