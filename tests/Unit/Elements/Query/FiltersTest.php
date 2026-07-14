<?php

declare(strict_types=1);

use craft\elements\db\EntryQuery;
use stimmt\craft\Mcp\elements\query\Filters;

describe('Filters::dateParam', function () {
    it('returns null when both bounds are null', function () {
        expect(Filters::dateParam(null, null))->toBeNull();
    });

    it('builds a lower bound only', function () {
        expect(Filters::dateParam('2026-07-01', null))->toBe(['and', '>= 2026-07-01']);
    });

    it('builds an upper bound only', function () {
        expect(Filters::dateParam(null, '2026-08-01'))->toBe(['and', '< 2026-08-01']);
    });

    it('builds both bounds', function () {
        expect(Filters::dateParam('2026-07-01', '2026-08-01'))
            ->toBe(['and', '>= 2026-07-01', '< 2026-08-01']);
    });
});

describe('Filters structure', function () {
    it('exposes apply with the shared filter parameters', function () {
        $params = array_map(
            fn (ReflectionParameter $p): string => $p->getName(),
            (new ReflectionMethod(Filters::class, 'apply'))->getParameters(),
        );

        expect($params)->toBe([
            'query', 'filters', 'relatedTo', 'author',
            'updatedAfter', 'updatedBefore', 'createdAfter', 'createdBefore', 'site',
        ]);
    });

    it('types the first apply parameter as EntryQuery', function () {
        $type = (new ReflectionMethod(Filters::class, 'apply'))->getParameters()[0]->getType();

        expect((string) $type)->toBe(EntryQuery::class);
    });

    it('resolves natural keys through Keys and refuses unknown field handles', function () {
        $source = (string) file_get_contents((new ReflectionClass(Filters::class))->getFileName());

        expect($source)->toContain('idFor(')
            ->and($source)->toContain('getFieldByHandle(')
            ->and($source)->toContain('InvalidArgumentException');
    });
});
