<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

// pest()->extend(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeValidResponse', function () {
    return $this->toBeArray()
        ->toHaveKey('success');
});

expect()->extend('toBeSuccessResponse', function () {
    return $this->toBeArray()
        ->toHaveKey('success')
        ->and($this->value['success'])->toBeTrue();
});

expect()->extend('toBeFoundResponse', function () {
    return $this->toBeArray()
        ->toHaveKey('found')
        ->and($this->value['found'])->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function createMockElement(int $id, ?string $title = null, ?string $slug = null): object {
    return new class ($id, $title, $slug) {
        public function __construct(
            public int $id,
            public ?string $title,
            public ?string $slug,
        ) {
        }
    };
}
