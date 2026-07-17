<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use Craft;
use Mcp\Schema\Notification\ResourceUpdatedNotification;
use Mcp\Server\RequestContext;
use Mcp\Server\Resource\SessionSubscriptionManager;
use Throwable;

/**
 * Pushes notifications/resources/updated for a craft:// URI, but only to a
 * session that actually called resources/subscribe on it first.
 * SessionSubscriptionManager stores subscriptions on the session itself, so
 * a fresh instance per call is exactly as correct as a shared one. Fail-open
 * by design, matching ConfigFreshness: a broken push must never turn an
 * otherwise successful write into a reported tool failure.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class ResourceChangeNotifier {
    public static function notify(?RequestContext $context, string $uri): void {
        if ($context === null) {
            return;
        }

        try {
            $subscriptions = new SessionSubscriptionManager();
            if (!$subscriptions->isSubscribed($context->getSession(), $uri)) {
                return;
            }

            $context->getClientGateway()->notify(new ResourceUpdatedNotification($uri));
        } catch (Throwable $e) {
            Craft::warning('Resource change notification failed: ' . $e->getMessage(), __METHOD__);
        }
    }
}
