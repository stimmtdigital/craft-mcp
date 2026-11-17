<?php

declare(strict_types=1);

use stimmt\craft\Mcp\insight\Advisories;

describe('Advisories::affects()', function () {
    it('decides an installed version against a Composer range', function (string $installed, string $range, bool $expected) {
        expect(Advisories::affects($installed, $range))->toBe($expected);
    })->with([
        'inside the first range' => ['1.1.0', '>=1.0,<1.2.3|>=2.0,<2.0.1', true],
        'the fixed release' => ['1.2.3', '>=1.0,<1.2.3|>=2.0,<2.0.1', false],
        'between the ranges' => ['1.5.0', '>=1.0,<1.2.3|>=2.0,<2.0.1', false],
        'inside the second range' => ['2.0.0', '>=1.0,<1.2.3|>=2.0,<2.0.1', true],
        'a v-prefixed tag' => ['v2.0.0', '>=2.0,<2.0.1', true],
        'below every range' => ['0.9.0', '>=1.0,<1.2.3', false],
        'a pre-release inside the range' => ['1.2.0-beta.1', '>=1.0,<1.2.3', true],
        // Composer reads <1.2.3 as <1.2.3.0-dev, so a pre-release of the fixed
        // version is outside it. composer audit answers the same; this pins it.
        'a pre-release of the fixed version, as Composer reads it' => ['1.2.3-beta.1', '>=1.0,<1.2.3', false],
        'a dev branch, which Composer does not order against releases' => ['dev-main', '>=1.0,<2.0', false],
    ]);

    it('says it cannot tell, rather than "not affected", when a side does not parse', function (string $installed, string $range) {
        expect(Advisories::affects($installed, $range))->toBeNull();
    })->with([
        'an unreadable range' => ['1.0.0', '>=1.0,<<<1.2'],
        'an empty range' => ['1.0.0', ''],
        'an unreadable version' => ['not a version', '>=1.0'],
    ]);
});

describe('Advisories::match()', function () {
    $advisory = static fn (array $overrides = []): array => [
        'advisoryId' => 'PKSA-abcd-efgh-ijkl',
        'packageName' => 'acme/lib',
        'remoteId' => 'GHSA-xxxx',
        'title' => 'Remote code execution',
        'link' => 'https://github.com/advisories/GHSA-xxxx',
        'cve' => 'CVE-2026-0001',
        'affectedVersions' => '>=1.0,<1.2.3',
        'source' => 'GitHub',
        'sources' => [['name' => 'GitHub', 'remoteId' => 'GHSA-xxxx']],
        'reportedAt' => '2026-10-01 12:00:00',
        'composerRepository' => 'https://packagist.org',
        'severity' => 'high',
        ...$overrides,
    ];

    it('reports an advisory covering the installed version, in the named shape only', function () use ($advisory) {
        $result = Advisories::match(['acme/lib' => '1.1.0'], ['acme/lib' => [$advisory()]]);

        expect($result['advisories'])->toBe([[
            'package' => 'acme/lib',
            'installedVersion' => '1.1.0',
            'advisoryId' => 'PKSA-abcd-efgh-ijkl',
            'cve' => 'CVE-2026-0001',
            'title' => 'Remote code execution',
            'link' => 'https://github.com/advisories/GHSA-xxxx',
            'severity' => 'high',
            'affectedVersions' => '>=1.0,<1.2.3',
            'reportedAt' => '2026-10-01 12:00:00',
        ]])->and($result['undecided'])->toBe([])
            ->and($result['uncovered'])->toBe([]);
    });

    it('drops an advisory the installed version is outside of', function () use ($advisory) {
        $result = Advisories::match(['acme/lib' => '1.2.3'], ['acme/lib' => [$advisory()]]);

        expect($result['advisories'])->toBe([]);
    });

    it('keeps the optional fields null when Packagist sends none', function () use ($advisory) {
        $result = Advisories::match(['acme/lib' => '1.0.0'], ['acme/lib' => [$advisory(['cve' => null, 'severity' => null])]]);

        expect($result['advisories'][0]['cve'])->toBeNull()
            ->and($result['advisories'][0]['severity'])->toBeNull();
    });

    it('names an advisory it could not decide, apart from the matches', function () use ($advisory) {
        $result = Advisories::match(['acme/lib' => '1.0.0'], ['acme/lib' => [$advisory(['affectedVersions' => '>=1.0,<<<1'])]]);

        expect($result['advisories'])->toBe([])
            ->and($result['undecided'])->toBe([[
                'package' => 'acme/lib',
                'installedVersion' => '1.0.0',
                'advisoryId' => 'PKSA-abcd-efgh-ijkl',
                'affectedVersions' => '>=1.0,<<<1',
            ]]);
    });

    // Packagist answers a known-clean package with an empty list and leaves
    // out one it has no data on, so absence is "not checked", never "safe".
    it('lists the packages Packagist did not answer for as uncovered', function () {
        $result = Advisories::match(
            ['acme/clean' => '1.0.0', 'private/fork' => '1.0.0'],
            ['acme/clean' => []],
        );

        expect($result['uncovered'])->toBe(['private/fork'])
            ->and($result['advisories'])->toBe([]);
    });

    it('checks every advisory of every package', function () use ($advisory) {
        $result = Advisories::match(
            ['acme/lib' => '1.1.0', 'other/pkg' => '2.0.0'],
            [
                'acme/lib' => [$advisory(), $advisory(['advisoryId' => 'PKSA-2', 'affectedVersions' => '>=1.1,<1.1.5'])],
                'other/pkg' => [$advisory(['advisoryId' => 'PKSA-3', 'affectedVersions' => '>=2.0,<2.0.1'])],
            ],
        );

        expect(array_column($result['advisories'], 'advisoryId'))->toBe(['PKSA-abcd-efgh-ijkl', 'PKSA-2', 'PKSA-3']);
    });
});
