<?php

declare(strict_types=1);

use stimmt\craft\Mcp\support\Serializer;

describe('Serializer::serialize() with scalars', function () {
    it('returns null as-is', function () {
        expect(Serializer::serialize(null))->toBeNull();
    });

    it('returns integers as-is', function () {
        expect(Serializer::serialize(42))->toBe(42);
        expect(Serializer::serialize(0))->toBe(0);
        expect(Serializer::serialize(-1))->toBe(-1);
    });

    it('returns floats as-is', function () {
        expect(Serializer::serialize(3.14))->toBe(3.14);
        expect(Serializer::serialize(0.0))->toBe(0.0);
    });

    it('returns strings as-is', function () {
        expect(Serializer::serialize('hello'))->toBe('hello');
        expect(Serializer::serialize(''))->toBe('');
    });

    it('returns booleans as-is', function () {
        expect(Serializer::serialize(true))->toBeTrue();
        expect(Serializer::serialize(false))->toBeFalse();
    });
});

describe('Serializer::serialize() with dates', function () {
    it('formats DateTime objects', function () {
        $date = new DateTime('2024-06-15 14:30:00');
        $result = Serializer::serialize($date);

        expect($result)->toBe('2024-06-15 14:30:00');
    });

    it('formats DateTimeImmutable objects', function () {
        $date = new DateTimeImmutable('2024-01-01 00:00:00');
        $result = Serializer::serialize($date);

        expect($result)->toBe('2024-01-01 00:00:00');
    });
});

describe('Serializer::serialize() with arrays', function () {
    it('serializes simple arrays', function () {
        $result = Serializer::serialize([1, 2, 3]);

        expect($result)->toBe([1, 2, 3]);
    });

    it('serializes associative arrays', function () {
        $result = Serializer::serialize(['name' => 'John', 'age' => 30]);

        expect($result)->toBe(['name' => 'John', 'age' => 30]);
    });

    it('serializes nested arrays', function () {
        $result = Serializer::serialize([
            'user' => ['name' => 'John'],
            'tags' => ['php', 'craft'],
        ]);

        expect($result)->toBe([
            'user' => ['name' => 'John'],
            'tags' => ['php', 'craft'],
        ]);
    });

    it('serializes arrays with dates', function () {
        $date = new DateTime('2024-06-15 14:30:00');
        $result = Serializer::serialize(['created' => $date]);

        expect($result)->toBe(['created' => '2024-06-15 14:30:00']);
    });

    it('truncates large arrays at 100 items', function () {
        $largeArray = range(1, 150);
        $result = Serializer::serialize($largeArray);

        expect($result)
            ->toHaveCount(101) // 100 items + __truncated key
            ->toHaveKey('__truncated');
    });
});

describe('Serializer::serialize() with depth limit', function () {
    it('respects max depth of 5', function () {
        $deepArray = ['l1' => ['l2' => ['l3' => ['l4' => ['l5' => ['l6' => 'deep']]]]]];
        $result = Serializer::serialize($deepArray);

        // At depth 5, l6 should be '[max depth reached]'
        expect($result['l1']['l2']['l3']['l4']['l5']['l6'])->toBe('[max depth reached]');
    });

    it('allows content up to depth 5', function () {
        $nestedArray = ['l1' => ['l2' => ['l3' => ['l4' => ['l5' => 'value']]]]];
        $result = Serializer::serialize($nestedArray);

        expect($result['l1']['l2']['l3']['l4']['l5'])->toBe('value');
    });
});

describe('Serializer::serialize() with objects', function () {
    it('serializes objects with toArray method', function () {
        $obj = new class () {
            public function toArray(): array {
                return ['id' => 1, 'name' => 'Test'];
            }
        };

        $result = Serializer::serialize($obj);

        expect($result)
            ->toHaveKey('__class')
            ->toHaveKey('data')
            ->and($result['data'])->toBe(['id' => 1, 'name' => 'Test']);
    });

    it('serializes objects with __toString method', function () {
        $obj = new class () {
            public function __toString(): string {
                return 'StringableObject';
            }
        };

        $result = Serializer::serialize($obj);

        expect($result)->toBe('StringableObject');
    });

    it('serializes Traversable objects', function () {
        $obj = new ArrayIterator(['a', 'b', 'c']);
        $result = Serializer::serialize($obj);

        expect($result)
            ->toHaveKey('__class')
            ->toHaveKey('items')
            ->and($result['items'])->toBe(['a', 'b', 'c']);
    });

    it('returns class name for unknown objects', function () {
        $obj = new stdClass();
        $result = Serializer::serialize($obj);

        expect($result)
            ->toHaveKey('__class')
            ->and($result['__class'])->toBe('stdClass');
    });

    it('prefers toArray over __toString', function () {
        $obj = new class () {
            public function toArray(): array {
                return ['from' => 'toArray'];
            }

            public function __toString(): string {
                return 'from __toString';
            }
        };

        $result = Serializer::serialize($obj);

        expect($result)
            ->toHaveKey('data')
            ->and($result['data'])->toBe(['from' => 'toArray']);
    });
});

describe('Serializer::serialize() edge cases', function () {
    it('handles empty array', function () {
        expect(Serializer::serialize([]))->toBe([]);
    });

    it('handles mixed type arrays', function () {
        $result = Serializer::serialize([
            'string' => 'value',
            'int' => 42,
            'bool' => true,
            'null' => null,
            'array' => [1, 2],
        ]);

        expect($result)->toBe([
            'string' => 'value',
            'int' => 42,
            'bool' => true,
            'null' => null,
            'array' => [1, 2],
        ]);
    });
});
