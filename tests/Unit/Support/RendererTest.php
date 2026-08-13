<?php

declare(strict_types=1);

use stimmt\craft\Mcp\support\Palette;
use stimmt\craft\Mcp\support\Renderer;

function renderPlain(array $payload): string {
    return (new Renderer(new Palette(false)))->render($payload);
}

describe('Renderer key-value maps', function () {
    it('aligns a flat map and keeps JSON scalar semantics readable', function () {
        $text = renderPlain([
            'driver' => 'mysql',
            'serverVersion' => '8.0.35',
            'port' => 3306,
            'primary' => true,
            'charset' => null,
        ]);

        expect($text)->toBe(<<<'TEXT'
            driver:        mysql
            serverVersion: 8.0.35
            port:          3306
            primary:       true
            charset:       null
            TEXT);
    });

    it('renders a count breakdown as an indented block under its key', function () {
        $text = renderPlain([
            'total' => 15,
            'buckets' => ['news' => 12, 'blog' => 3],
            'groupBy' => 'section',
        ]);

        expect($text)->toBe(<<<'TEXT'
            total:   15
            buckets:
              news: 12
              blog: 3
            groupBy: section
            TEXT);
    });

    it('indents nested maps without flattening them', function () {
        $text = renderPlain([
            'site' => [
                'id' => 1,
                'handle' => 'default',
                'group' => ['id' => 3, 'name' => 'Main'],
            ],
        ]);

        expect($text)->toBe(<<<'TEXT'
            site:
              id:     1
              handle: default
              group:
                id:   3
                name: Main
            TEXT);
    });

    it('inlines a list of scalars and marks an empty one', function () {
        $text = renderPlain(['columns' => ['id', 'title'], 'warnings' => []]);

        expect($text)->toBe(<<<'TEXT'
            columns:  id, title
            warnings: (empty)
            TEXT);
    });

    it('indents a multiline string under its key', function () {
        $text = renderPlain(['message' => "first\nsecond", 'level' => 'error']);

        expect($text)->toBe(<<<'TEXT'
            message:
              first
              second
            level: error
            TEXT);
    });

    it('marks an empty payload', function () {
        expect(renderPlain([]))->toBe('(empty)');
    });
});

describe('Renderer tables', function () {
    it('renders a list of uniform rows as an aligned table', function () {
        $text = renderPlain([
            'count' => 2,
            'sites' => [
                ['id' => 1, 'handle' => 'default', 'primary' => true],
                ['id' => 2, 'handle' => 'nl', 'primary' => false],
            ],
        ]);

        expect($text)->toBe(<<<'TEXT'
            count: 2
            sites:
              id  handle   primary
              --  -------  -------
              1   default  true
              2   nl       false
            TEXT);
    });

    it('renders a top-level list of rows without a key', function () {
        $text = renderPlain([
            ['name' => 'entries', 'count' => 12],
            ['name' => 'assets', 'count' => 3],
        ]);

        expect($text)->toBe(<<<'TEXT'
            name     count
            -------  -----
            entries  12
            assets   3
            TEXT);
    });

    it('turns a map of uniform rows into a table keyed by its first column', function () {
        $text = renderPlain([
            'elements' => ['label' => 'Total elements', 'count' => 1234],
            'entries' => ['label' => 'Entries', 'count' => 56],
        ]);

        expect($text)->toBe(<<<'TEXT'
                      label           count
            --------  --------------  -----
            elements  Total elements  1234
            entries   Entries         56
            TEXT);
    });

    it('fills a cell the row does not carry rather than dropping the column', function () {
        $text = renderPlain([
            'rows' => [
                ['name' => 'entries', 'count' => 12],
                ['name' => 'ghost', 'error' => 'Table does not exist'],
            ],
        ]);

        expect($text)->toBe(<<<'TEXT'
            rows:
              name     count  error
              -------  -----  --------------------
              entries  12
              ghost           Table does not exist
            TEXT);
    });

    it('falls back to per-item blocks when a row carries a nested value', function () {
        $text = renderPlain([
            'entries' => [
                ['id' => 1, 'fields' => ['title' => 'Hello']],
                ['id' => 2, 'fields' => ['title' => 'Goodbye']],
            ],
        ]);

        expect($text)->toBe(<<<'TEXT'
            entries:
              - id: 1
                fields:
                  title: Hello
              - id: 2
                fields:
                  title: Goodbye
            TEXT);
    });
});

describe('Renderer fallbacks', function () {
    it('encodes a value it cannot lay out rather than dropping it', function () {
        $text = renderPlain(['handler' => (object) ['a' => 1]]);

        expect($text)->toBe('handler: {"a":1}');
    });

    it('falls back to pretty JSON past the depth limit, losing nothing', function () {
        $deep = ['depth' => 0];
        $branch = &$deep;
        foreach (range(1, 8) as $level) {
            $branch['next'] = ['depth' => $level];
            $branch = &$branch['next'];
        }
        unset($branch);

        $text = renderPlain($deep);

        expect($text)->toStartWith('depth: 0')
            ->and($text)->toContain('"depth": 6')
            ->and($text)->toContain('"depth": 8');
    });

    it('never throws on a payload it cannot represent', function () {
        $text = renderPlain(['closure' => static fn (): int => 1]);

        expect($text)->toBeString()->not->toBe('');
    });
});

describe('Renderer colour', function () {
    it('emits no escape sequence when the palette is off', function () {
        $text = (new Renderer(new Palette(false)))->render([
            'count' => 1,
            'rows' => [['id' => 1, 'title' => 'Hello']],
        ]);

        expect($text)->not->toContain("\033");
    });

    it('colours keys and table headers when the palette is on', function () {
        $text = (new Renderer(new Palette(true)))->render([
            'count' => 1,
            'rows' => [['id' => 1, 'title' => 'Hello']],
        ]);

        expect($text)->toContain("\033");
    });

    it('lays out identically with colour stripped back off', function () {
        $payload = [
            'count' => 1,
            'rows' => [['id' => 1, 'title' => 'Hello']],
        ];

        $colored = (new Renderer(new Palette(true)))->render($payload);
        $plain = (new Renderer(new Palette(false)))->render($payload);

        expect(preg_replace('/\033\[[0-9;]*m/', '', $colored))->toBe($plain);
    });
});
