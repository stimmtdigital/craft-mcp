<?php

declare(strict_types=1);

use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Schema\Request\PingRequest;
use Mcp\Server\RequestContext;
use Mcp\Server\Resource\SessionSubscriptionManager;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use stimmt\craft\Mcp\support\ResourceChangeNotifier;

/**
 * Runs the notifier inside a real Fiber because ClientGateway::notify()
 * suspends the current one (Mcp\Server\ClientGateway::notify()); that is
 * exactly how Protocol intercepts a server-initiated push from a running
 * tool call, on stdio and streamable HTTP alike. Fiber::start() returns the
 * suspended value, or null if the callback returns without ever suspending
 * (verified against php -r before relying on it here).
 *
 * @return array{suspended: mixed, terminated: bool}
 */
function runNotifyInFiber(RequestContext $context, string $uri): array {
    $fiber = new Fiber(static function () use ($context, $uri): void {
        ResourceChangeNotifier::notify($context, $uri);
    });

    $suspended = $fiber->start();

    return ['suspended' => $suspended, 'terminated' => $fiber->isTerminated()];
}

describe('ResourceChangeNotifier', function () {
    it('sends a ResourceUpdatedNotification when the session subscribed to the URI', function () {
        $session = new Session(new InMemorySessionStore());
        (new SessionSubscriptionManager())->subscribe($session, 'craft://entries/news/hello-world');
        $context = new RequestContext($session, new PingRequest());

        $result = runNotifyInFiber($context, 'craft://entries/news/hello-world');

        expect($result['terminated'])->toBeFalse()
            ->and($result['suspended'])->toBeArray()
            ->and($result['suspended']['type'])->toBe('notification')
            ->and($result['suspended']['notification'])->toBeInstanceOf(ResourceUpdatedNotification::class)
            ->and($result['suspended']['notification']->uri)->toBe('craft://entries/news/hello-world');
    });

    it('does not notify a session that never subscribed', function () {
        $session = new Session(new InMemorySessionStore());
        $context = new RequestContext($session, new PingRequest());

        $result = runNotifyInFiber($context, 'craft://entries/news/hello-world');

        expect($result['terminated'])->toBeTrue()
            ->and($result['suspended'])->toBeNull();
    });

    it('does not notify a session subscribed to a different URI', function () {
        $session = new Session(new InMemorySessionStore());
        (new SessionSubscriptionManager())->subscribe($session, 'craft://entries/news/other-entry');
        $context = new RequestContext($session, new PingRequest());

        $result = runNotifyInFiber($context, 'craft://entries/news/hello-world');

        expect($result['terminated'])->toBeTrue()
            ->and($result['suspended'])->toBeNull();
    });

    it('is a safe no-op without a request context', function () {
        ResourceChangeNotifier::notify(null, 'craft://entries/news/hello-world');

        expect(true)->toBeTrue();
    });
});
