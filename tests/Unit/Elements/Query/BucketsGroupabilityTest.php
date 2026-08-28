<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/Fixtures/CraftStub.php';
require_once dirname(__DIR__, 4) . '/vendor/yiisoft/yii2/Yii.php';

use craft\base\FieldInterface;
use craft\fields\PlainText;
use stimmt\craft\Mcp\elements\query\Buckets;
use stimmt\craft\Mcp\Tests\Fixtures\Layouts;

describe('Buckets groupable fields', function () {
    beforeEach(function () {
        $this->originalApp = Craft::$app;
        $matrix = Layouts::matrix('contentBuilder');

        Craft::$app = new class ($matrix) {
            public function __construct(private readonly FieldInterface $matrix) {
            }

            public function getFields(): object {
                return new class ($this->matrix) {
                    public function __construct(private readonly FieldInterface $matrix) {
                    }

                    public function getFieldByHandle(string $handle): ?FieldInterface {
                        return match ($handle) {
                            'contentBuilder' => $this->matrix,
                            'vehicleType' => new PlainText(['handle' => 'vehicleType']),
                            default => null,
                        };
                    }
                };
            }
        };

        $this->groupable = fn (string $handle): FieldInterface => (new ReflectionMethod(Buckets::class, 'groupableField'))
            ->invoke(new Buckets(), $handle);
    });

    afterEach(function () {
        Craft::$app = $this->originalApp;
    });

    it('accepts a field whose value describes the entry', function () {
        expect(($this->groupable)('vehicleType')->handle)->toBe('vehicleType');
    });

    // The defect: 57 entries reported 70 across the buckets, because every
    // block of every entry opened a bucket of its own.
    it('refuses a container field rather than counting one entry many times', function () {
        try {
            ($this->groupable)('contentBuilder');
        } catch (InvalidArgumentException $e) {
            expect($e->getMessage())
                ->toContain("'contentBuilder'")
                ->toContain('container field')
                ->toContain('more than the total')
                ->toContain('month:dateUpdated');

            return;
        }

        $this->fail('Expected an InvalidArgumentException for a container groupBy');
    });

    it('still refuses a handle no field answers to', function () {
        expect(fn () => ($this->groupable)('nope'))
            ->toThrow(InvalidArgumentException::class, "Unknown groupBy 'nope'");
    });
});
