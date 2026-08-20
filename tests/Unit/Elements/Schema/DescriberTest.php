<?php

declare(strict_types=1);

use craft\base\Field;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\TitleField;
use craft\fields\Entries;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\FieldLayout;
use stimmt\craft\Mcp\elements\schema\Describer;
use stimmt\craft\Mcp\Tests\Fixtures\Layouts;

/**
 * A Matrix field stub whose entry types are fixed at construction, the same
 * pattern ShapeTest uses: Matrix::getEntryTypes() normally needs Craft
 * services this suite doesn't stub.
 *
 * @param array<int, EntryType> $entryTypes
 */
function describerMatrixField(string $handle, array $entryTypes): Matrix {
    return new class ($handle, $entryTypes) extends Matrix {
        /** @param array<int, EntryType> $entryTypes */
        public function __construct(private readonly string $matrixHandle, private readonly array $entryTypes) {
            parent::__construct(['handle' => $matrixHandle]);
        }

        public function getEntryTypes(): array {
            return $this->entryTypes;
        }
    };
}

/**
 * An entry-type stub whose field layout is fixed at construction. Name is
 * required explicitly (rather than left to default to handle or null) so
 * assertions can tell the two attributes apart.
 */
function describerBlockType(string $handle, string $name, bool $hasTitleField, FieldLayout $layout): EntryType {
    return new class ($handle, $name, $hasTitleField, $layout) extends EntryType {
        public function __construct(
            private readonly string $typeHandle,
            private readonly string $typeName,
            private readonly bool $titleField,
            private readonly FieldLayout $blockLayout,
        ) {
            parent::__construct(['handle' => $typeHandle, 'name' => $typeName, 'hasTitleField' => $titleField]);
        }

        public function getFieldLayout(): FieldLayout {
            return $this->blockLayout;
        }
    };
}

describe('Describer', function () {
    it('describes custom fields with layout-level overrides', function () {
        $body = new CustomField(new PlainText(['handle' => 'body', 'name' => 'Body', 'instructions' => 'Write here']));
        $body->required = true;

        // showUnpermittedSections bypasses the Craft::$app permission lookup in getInputSources(),
        // which isn't available in the unit test environment.
        $related = new CustomField(new Entries([
            'handle' => 'related',
            'name' => 'Related',
            'showUnpermittedSections' => true,
        ]));

        $fields = (new Describer())->describe(Layouts::with([$body, $related]));

        $byHandle = array_column($fields, null, 'handle');
        expect($byHandle)->toHaveKeys(['body', 'related'])
            ->and($byHandle['body']['required'])->toBeTrue()
            ->and($byHandle['body']['instructions'])->toBe('Write here')
            ->and($byHandle['body']['kind'])->toBe('scalar')
            ->and($byHandle['related']['kind'])->toBe('relation')
            ->and($byHandle['related']['target']['elementType'])->toBe(craft\elements\Entry::class)
            ->and($byHandle['related']['target']['sources'])->toBe('*');
    });

    it('includes an input shape and derives kind from it', function () {
        $body = new CustomField(new PlainText(['handle' => 'body']));
        $related = new CustomField(new Entries(['handle' => 'related', 'showUnpermittedSections' => true]));

        $byHandle = array_column((new Describer())->describe(Layouts::with([$body, $related])), null, 'handle');

        expect($byHandle['body']['input']['kind'])->toBe('scalar')
            ->and($byHandle['body']['kind'])->toBe('scalar')
            ->and($byHandle['related']['kind'])->toBe('relation')
            ->and($byHandle['related']['input']['item'])->toBe(['section', 'slug']);
    });

    it('lists native layout fields', function () {
        $natives = (new Describer())->natives(Layouts::with([new TitleField()]));

        // TitleField has no per-layout label override, so name falls back to the raw ''
        // (the translated default label would need Craft services, unavailable here).
        expect($natives)->toHaveCount(1)
            ->and($natives[0]['attribute'])->toBe('title')
            ->and($natives[0]['name'])->toBe('')
            ->and($natives[0]['mandatory'])->toBeTrue();
    });

    it('returns empty for a null layout', function () {
        expect((new Describer())->describe(null))->toBe([])
            ->and((new Describer())->natives(null))->toBe([]);
    });

    it('expands a matrix field once, in blockTypes, never a second time inside input', function () {
        $blockLayout = Layouts::with([new CustomField(new PlainText([
            'handle' => 'buttonText',
            'name' => 'Button Text',
            'instructions' => 'Leave empty to remove the button',
        ]))]);
        $textCard = describerBlockType('textCard', 'Text Card', true, $blockLayout);
        $cards = describerMatrixField('cards', [$textCard]);

        $body = new CustomField(new PlainText(['handle' => 'body', 'name' => 'Body']));
        $fields = (new Describer())->describe(Layouts::with([$body, new CustomField($cards)]), depth: 1);
        $byHandle = array_column($fields, null, 'handle');

        // input carries kind and a flat block-type summary, never the
        // recursive tree: no 'fields' key riding along with it.
        expect($byHandle['cards']['input']['kind'])->toBe('matrix')
            ->and($byHandle['cards']['input']['blockTypes'])->toBe(['textCard' => ['hasTitleField' => true]]);

        // blockTypes is the one place the nested field detail lives,
        // including the instructions the input copy would otherwise drop.
        $blockTypesByHandle = array_column($byHandle['cards']['blockTypes'], null, 'handle');
        $buttonText = array_column($blockTypesByHandle['textCard']['fields'], null, 'handle')['buttonText'];

        expect($blockTypesByHandle['textCard']['name'])->toBe('Text Card')
            ->and($blockTypesByHandle['textCard']['hasTitleField'])->toBeTrue()
            ->and($buttonText['handle'])->toBe('buttonText')
            ->and($buttonText['name'])->toBe('Button Text')
            ->and($buttonText['type'])->toBe(PlainText::class)
            ->and($buttonText['kind'])->toBe('scalar')
            ->and($buttonText['required'])->toBeFalse()
            ->and($buttonText['instructions'])->toBe('Leave empty to remove the button');

        // A sibling scalar field at the same layout level is untouched by
        // any of the matrix-specific handling above.
        expect($byHandle['body']['kind'])->toBe('scalar')
            ->and($byHandle['body']['input'])->not->toHaveKey('blockTypes');
    });

    it('still names a top-level matrix field\'s block types at depth 0', function () {
        $blockLayout = Layouts::with([new CustomField(new PlainText(['handle' => 'buttonText']))]);
        $textCard = describerBlockType('textCard', 'Text Card', true, $blockLayout);
        $cards = describerMatrixField('cards', [$textCard]);

        $fields = (new Describer())->describe(Layouts::with([new CustomField($cards)]), depth: 0);
        $described = array_column($fields, null, 'handle')['cards'];

        // blockTypes: handles and names only, no field expansion at depth 0.
        expect($described['blockTypes'])->toBe([['handle' => 'textCard', 'name' => 'Text Card']]);

        // input's flat block-type summary is depth-independent, so it still
        // names the same block type at depth 0 too.
        expect($described['input']['blockTypes'])->toBe(['textCard' => ['hasTitleField' => true]]);
    });

    it('still names a nested matrix field\'s block types once its depth budget is exhausted', function () {
        $innerLayout = Layouts::with([new CustomField(new PlainText(['handle' => 'label']))]);
        $innerBlock = describerBlockType('innerBlock', 'Inner Block', false, $innerLayout);
        $inner = describerMatrixField('inner', [$innerBlock]);

        $wrapperLayout = Layouts::with([new CustomField($inner)]);
        $wrapper = describerBlockType('wrapper', 'Wrapper', false, $wrapperLayout);
        $sections = describerMatrixField('sections', [$wrapper]);

        // depth: 1 expands `sections`' own block types (the `wrapper` type
        // and its fields) but exhausts the budget for `inner`, one level
        // further down.
        $fields = (new Describer())->describe(Layouts::with([new CustomField($sections)]), depth: 1);
        $sectionsBlockTypes = array_column($fields, null, 'handle')['sections']['blockTypes'];
        $wrapperFields = array_column($sectionsBlockTypes, null, 'handle')['wrapper']['fields'];
        $innerField = array_column($wrapperFields, null, 'handle')['inner'];

        // Previously this fell back to an empty list because the fallback
        // only fired for the outermost matrix field (the `top` flag); now
        // any matrix names its block types once its own budget runs out.
        expect($innerField['blockTypes'])->toBe([['handle' => 'innerBlock', 'name' => 'Inner Block']]);
    });

    // The multi-site question an agent actually has before writing: if I set
    // this field on one site, does another site change with it? Absent
    // entirely on a single-site install, where there is no other site.
    it('reports per-field translation only where a second site exists', function () {
        $body = new CustomField(new PlainText(['handle' => 'body', 'name' => 'Body']));

        $single = (new Describer(multiSite: false))->describe(Layouts::with([$body]));
        $multi = (new Describer(multiSite: true))->describe(Layouts::with([$body]));

        expect($single[0])->not->toHaveKey('translation')
            ->and($multi[0]['translation'])->toHaveKeys(['method', 'perSite']);
    });

    it('calls a field per-site only when every site gets its own value', function () {
        $shared = new CustomField(new PlainText(['handle' => 'shared', 'translationMethod' => Field::TRANSLATION_METHOD_NONE]));
        $perSite = new CustomField(new PlainText(['handle' => 'perSite', 'translationMethod' => Field::TRANSLATION_METHOD_SITE]));
        $byLanguage = new CustomField(new PlainText(['handle' => 'byLanguage', 'translationMethod' => Field::TRANSLATION_METHOD_LANGUAGE]));

        $byHandle = array_column(
            (new Describer(multiSite: true))->describe(Layouts::with([$shared, $perSite, $byLanguage])),
            null,
            'handle',
        );

        // Anything short of per-site reads as false, because false is the
        // answer that makes the caller careful.
        expect($byHandle['shared']['translation']['perSite'])->toBeFalse()
            ->and($byHandle['perSite']['translation']['perSite'])->toBeTrue()
            ->and($byHandle['byLanguage']['translation']['perSite'])->toBeFalse()
            ->and($byHandle['shared']['translation']['method'])->toBe(Field::TRANSLATION_METHOD_NONE);
    });
});
