<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../Fixtures/CraftStub.php';

use craft\base\FieldInterface;
use craft\fields\Number;
use craft\fields\PlainText;
use stimmt\craft\Mcp\elements\Context;
use stimmt\craft\Mcp\elements\refs\FieldTranslator;
use stimmt\craft\Mcp\elements\refs\Registry;

function translatorFor(string $class, string $name): FieldTranslator {
    return new class ($class, $name) implements FieldTranslator {
        public function __construct(private readonly string $class, public readonly string $name) {}

        public function handles(FieldInterface $field): bool {
            return $field instanceof $this->class;
        }

        public function toKeys(FieldInterface $field, mixed $value, Context $context): mixed {
            return $this->name;
        }

        public function toIds(FieldInterface $field, mixed $value, Context $context): mixed {
            return $this->name;
        }
    };
}

describe('Registry', function () {
    beforeEach(function () {
        $db = new class () {
            public function getIsMysql(): bool {
                return false;
            }
        };

        Craft::$app = new class ($db) {
            public function __construct(private readonly object $db) {}

            public function getDb(): object {
                return $this->db;
            }
        };
    });

    it('selects the first matching translator', function () {
        $registry = new Registry();
        $registry->register(translatorFor(PlainText::class, 'text'));

        expect($registry->for(new PlainText())?->toKeys(new PlainText(), null, new Context()))->toBe('text')
            ->and($registry->for(new Number()))->toBeNull();
    });

    it('lets later registrations win (event-registered translators override built-ins)', function () {
        $registry = new Registry();
        $registry->register(translatorFor(PlainText::class, 'builtin'));
        $registry->register(translatorFor(PlainText::class, 'custom'));

        expect($registry->for(new PlainText())?->toKeys(new PlainText(), null, new Context()))->toBe('custom');
    });
});
