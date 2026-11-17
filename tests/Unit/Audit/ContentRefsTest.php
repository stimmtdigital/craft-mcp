<?php

declare(strict_types=1);

use stimmt\craft\Mcp\audit\ContentRefs;
use stimmt\craft\Mcp\audit\FieldRef;

/** [layout uid, handle, ref] per found tag. */
$found = static fn (array $refs): array => array_map(
    static fn (FieldRef $ref): array => [$ref->layoutUid, $ref->tag->handle, $ref->tag->ref],
    $refs,
);

describe('ContentRefs', function () use ($found) {
    // The shape Craft 5 writes to elements_sites.content: one object keyed by
    // field layout element UID, rich text as an HTML string, a Link field as
    // an object whose "value" is the tag.
    it('finds tags in rich text and Link field values, under their layout uid', function () use ($found) {
        $json = json_encode([
            'uid-body' => '<p><a href="{entry:12@1:url||https://example.com/about}">About</a></p>',
            'uid-link' => ['value' => '{asset:45@1:url}', 'type' => 'asset', 'label' => 'Brochure'],
            'uid-plain' => 'No links here',
        ], JSON_THROW_ON_ERROR);

        expect($found(ContentRefs::inJson($json)))->toBe([
            ['uid-body', 'entry', '12'],
            ['uid-link', 'asset', '45'],
        ]);
    });

    // A table field or a plugin's structured value nests strings deeper; a
    // link there is still a link.
    it('finds tags at any depth, and skips leaves that are not strings', function () use ($found) {
        $content = [
            'uid-table' => [
                ['col1' => 'Row one', 'col2' => '{category:7}'],
                ['col1' => 3, 'col2' => null, 'col3' => true],
            ],
            'uid-number' => 42,
        ];

        expect($found(ContentRefs::inContent($content)))->toBe([['uid-table', 'category', '7']]);
    });

    it('finds nothing in an empty, null or unreadable column', function (?string $json) {
        expect(ContentRefs::inJson($json))->toBe([]);
    })->with([
        'null' => [null],
        'empty string' => [''],
        'not JSON' => ['{entry:12'],
        'a JSON scalar' => ['"{entry:12}"'],
        'an empty object' => ['{}'],
    ]);
});
