<?php

declare(strict_types=1);

use Mcp\Server\Session\Session;
use Mcp\Server\Session\SessionStoreInterface;
use stimmt\craft\Mcp\support\Subscription;
use Symfony\Component\Uid\Uuid;

describe('Subscription', function () {
    beforeEach(function () {
        // The real SDK Session over a store that keeps rows in memory: the
        // subscription set is stored on the session, so the semantics under test
        // are the session's own, not a stand-in's.
        $this->store = new class () implements SessionStoreInterface {
            /** @var array<string, string> */
            public array $rows = [];

            public int $writes = 0;

            public function exists(Uuid $id): bool {
                return isset($this->rows[$id->toRfc4122()]);
            }

            public function read(Uuid $id): string|false {
                return $this->rows[$id->toRfc4122()] ?? false;
            }

            public function write(Uuid $id, string $data): bool {
                $this->writes++;
                $this->rows[$id->toRfc4122()] = $data;

                return true;
            }

            public function destroy(Uuid $id): bool {
                unset($this->rows[$id->toRfc4122()]);

                return true;
            }

            public function gc(): array {
                return [];
            }
        };

        $this->session = new Session($this->store, Uuid::v4());
        $this->subscriptions = new Subscription();
    });

    it('persists a subscription on the session', function () {
        $this->subscriptions->subscribe($this->session, 'craft://entries/news/hello');

        expect($this->store->writes)->toBe(1)
            ->and($this->subscriptions->isSubscribed($this->session, 'craft://entries/news/hello'))->toBeTrue();
    });

    it('matches a concrete subscription only against itself', function () {
        $this->subscriptions->subscribe($this->session, 'craft://entries/news/hello');

        expect($this->subscriptions->isSubscribed($this->session, 'craft://entries/news/other'))->toBeFalse();
    });

    // The finding: resources/subscribe accepts the template a client just read
    // from resources/templates/list, and every URI we notify is concrete.
    it('fires a template subscription for a concrete uri', function () {
        $this->subscriptions->subscribe($this->session, 'craft://entries/{section}/{slug}');

        expect($this->subscriptions->isSubscribed($this->session, 'craft://entries/news/hello'))->toBeTrue()
            ->and($this->subscriptions->isSubscribed($this->session, 'craft://entries/blog/another'))->toBeTrue();
    });

    it('keeps a template subscription inside its own shape', function () {
        $this->subscriptions->subscribe($this->session, 'craft://entries/{section}/{slug}');

        expect($this->subscriptions->isSubscribed($this->session, 'craft://assets/images/logo.png'))->toBeFalse()
            ->and($this->subscriptions->isSubscribed($this->session, 'craft://entries/news'))->toBeFalse()
            ->and($this->subscriptions->isSubscribed($this->session, 'craft://entries/news/hello/deeper'))->toBeFalse();
    });

    // A placeholder stands for one path segment, exactly as the registry
    // compiles it, so a slug containing a slash is a different resource.
    it('does not let a placeholder swallow a slash', function () {
        $this->subscriptions->subscribe($this->session, 'craft://entries/{section}/{slug}');

        expect($this->subscriptions->isSubscribed($this->session, 'craft://entries/news/nested/slug'))->toBeFalse();
    });

    it('unsubscribes a template again', function () {
        $this->subscriptions->subscribe($this->session, 'craft://entries/{section}/{slug}');
        $this->subscriptions->unsubscribe($this->session, 'craft://entries/{section}/{slug}');

        expect($this->subscriptions->isSubscribed($this->session, 'craft://entries/news/hello'))->toBeFalse();
    });

    it('reports no subscription on a session that never subscribed', function () {
        expect($this->subscriptions->isSubscribed($this->session, 'craft://entries/news/hello'))->toBeFalse();
    });

    // Regex metacharacters in a subscription key are literal, not a pattern: a
    // stored key is a URI, and only its {placeholders} are wildcards.
    it('treats the literal parts of a template as literal', function () {
        $this->subscriptions->subscribe($this->session, 'craft://entries/{section}/a.b');

        expect($this->subscriptions->isSubscribed($this->session, 'craft://entries/news/a.b'))->toBeTrue()
            ->and($this->subscriptions->isSubscribed($this->session, 'craft://entries/news/axb'))->toBeFalse();
    });
});
