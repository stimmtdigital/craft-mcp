<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/Fixtures/CraftStub.php';
require_once dirname(__DIR__, 4) . '/vendor/yiisoft/yii2/Yii.php';

use craft\base\ElementContainerFieldInterface;
use craft\base\Field;
use craft\base\NestedElementInterface;
use craft\elements\User;
use craft\enums\PropagationMethod;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\FieldLayout;
use stimmt\craft\Mcp\elements\schema\Translation;
use stimmt\craft\Mcp\Tests\Fixtures\Layouts;

/**
 * An entry-type stub whose field layout is fixed at construction: the real
 * getFieldLayout() resolves through Craft services this suite does not run.
 */
function translationBlockType(string $handle, FieldLayout $layout): EntryType {
    return new class ($handle, $layout) extends EntryType {
        public function __construct(
            private readonly string $typeHandle,
            private readonly FieldLayout $blockLayout,
        ) {
            parent::__construct(['handle' => $typeHandle]);
        }

        public function getFieldLayout(): FieldLayout {
            return $this->blockLayout;
        }
    };
}

/**
 * A Matrix carrying the settings Craft gives a real one: translationMethod
 * "site" (which site a block belongs to) alongside a propagation method
 * (whether the block itself is repeated on every site).
 *
 * @param EntryType[] $entryTypes
 */
function translationMatrix(string $handle, array $entryTypes, PropagationMethod $propagation = PropagationMethod::All): Matrix {
    $field = Layouts::matrix($handle, $entryTypes);
    $field->translationMethod = Field::TRANSLATION_METHOD_SITE;
    $field->propagationMethod = $propagation;

    return $field;
}

function translationBlock(string $handle, string $method): CustomField {
    return new CustomField(new PlainText(['handle' => $handle, 'translationMethod' => $method]));
}

/**
 * A container that is not a Matrix: a rich-text field that can hold nested
 * entries stores a value of its own as well, so its own translation method
 * still decides half the answer.
 *
 * @param array<int, object> $providers
 */
function translationContainer(string $method, array $providers = []): ElementContainerFieldInterface {
    return new class ($method, $providers) extends PlainText implements ElementContainerFieldInterface {
        /** @param array<int, object> $providers */
        public function __construct(string $method, private readonly array $providers) {
            parent::__construct(['handle' => 'richText', 'translationMethod' => $method]);
        }

        public function getFieldLayoutProviders(): array {
            return $this->providers;
        }

        public function getUriFormatForElement(NestedElementInterface $element): ?string {
            return null;
        }

        public function getRouteForElement(NestedElementInterface $element): mixed {
            return null;
        }

        public function getSupportedSitesForElement(NestedElementInterface $element): array {
            return [];
        }

        public function canViewElement(NestedElementInterface $element, User $user): ?bool {
            return null;
        }

        public function canSaveElement(NestedElementInterface $element, User $user): ?bool {
            return null;
        }

        public function canDuplicateElement(NestedElementInterface $element, User $user): ?bool {
            return null;
        }

        public function canDeleteElement(NestedElementInterface $element, User $user): ?bool {
            return null;
        }

        public function canDeleteElementForSite(NestedElementInterface $element, User $user): ?bool {
            return null;
        }
    };
}

describe('Translation::of', function () {
    it('answers for a plain field with its own translation method', function () {
        $translation = new Translation();

        $perSite = $translation->of(new PlainText(['handle' => 'a', 'translationMethod' => Field::TRANSLATION_METHOD_SITE]));
        $shared = $translation->of(new PlainText(['handle' => 'b', 'translationMethod' => Field::TRANSLATION_METHOD_NONE]));
        $byLanguage = $translation->of(new PlainText(['handle' => 'c', 'translationMethod' => Field::TRANSLATION_METHOD_LANGUAGE]));

        expect($perSite)->toBe(['method' => Field::TRANSLATION_METHOD_SITE, 'perSite' => true])
            ->and($shared['perSite'])->toBeFalse()
            ->and($byLanguage['perSite'])->toBeFalse()
            ->and($perSite)->not->toHaveKey('propagation');
    });

    // The reported defect: the container reported perSite true because the
    // Matrix itself is per-site, while writing a block on one site changed
    // every other site, because the block's own fields are shared.
    it('calls a matrix whose blocks stand on every site and hold shared fields not per-site', function () {
        $block = translationBlockType('contentBlock', Layouts::with([
            translationBlock('body', Field::TRANSLATION_METHOD_NONE),
            translationBlock('caption', Field::TRANSLATION_METHOD_SITE),
        ]));

        $out = (new Translation())->of(translationMatrix('contentBuilder', [$block]));

        expect($out['method'])->toBe(Field::TRANSLATION_METHOD_SITE)
            ->and($out['propagation'])->toBe('all')
            ->and($out['perSite'])->toBeFalse();
    });

    it('calls a matrix per-site when every nested value is per-site too', function () {
        $block = translationBlockType('contentBlock', Layouts::with([
            translationBlock('body', Field::TRANSLATION_METHOD_SITE),
        ]));

        expect((new Translation())->of(translationMatrix('contentBuilder', [$block]))['perSite'])->toBeTrue();
    });

    it('calls a matrix with localized blocks per-site whatever the blocks contain', function () {
        $block = translationBlockType('contentBlock', Layouts::with([
            translationBlock('body', Field::TRANSLATION_METHOD_NONE),
        ]));
        $field = translationMatrix('contentBuilder', [$block], PropagationMethod::None);

        expect((new Translation())->of($field))
            ->toBe(['method' => Field::TRANSLATION_METHOD_SITE, 'propagation' => 'none', 'perSite' => true]);
    });

    it('refuses to be shared when blocks propagate by language', function () {
        $block = translationBlockType('contentBlock', Layouts::with([
            translationBlock('body', Field::TRANSLATION_METHOD_SITE),
        ]));
        $field = translationMatrix('contentBuilder', [$block], PropagationMethod::Language);

        expect((new Translation())->of($field)['perSite'])->toBeFalse();
    });

    // A rich-text field that can hold entries is a container too, and walking
    // only its nested content would call a shared value per-site, which is the
    // dangerous direction to be wrong in.
    it('keeps a shared container shared, whatever it nests', function () {
        $block = translationBlockType('embedded', Layouts::with([
            translationBlock('body', Field::TRANSLATION_METHOD_SITE),
        ]));

        expect((new Translation())->of(translationContainer(Field::TRANSLATION_METHOD_NONE, [$block]))['perSite'])->toBeFalse()
            ->and((new Translation())->of(translationContainer(Field::TRANSLATION_METHOD_SITE, [$block]))['perSite'])->toBeTrue();
    });

    it('terminates on a matrix that nests itself', function () {
        $field = translationMatrix('cycle', []);
        $block = translationBlockType('loop', Layouts::with([new CustomField($field)]));
        (new ReflectionObject($field))->getProperty('_entryTypes')->setValue($field, [$block]);

        expect((new Translation())->of($field)['perSite'])->toBeTrue();
    });
});
