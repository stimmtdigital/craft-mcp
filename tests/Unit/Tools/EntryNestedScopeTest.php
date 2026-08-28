<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';
require_once __DIR__ . '/../../Fixtures/CustomFieldBehaviorStub.php';

use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\models\EntryType;
use craft\models\Section;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Discovery\DocBlockParser;
use Mcp\Capability\Discovery\SchemaGenerator;
use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\tools\EntryTools;

/**
 * What "list entries across every section" answers with.
 *
 * In Craft 5 a Matrix block IS an entry, so an unfiltered Entry::find()
 * returns blocks beside the content that was asked for. On the install this
 * was found on, 45 of 105 rows were title-less, slug-less blocks belonging to
 * no section, and they sorted first, so the whole first page held nothing a
 * human would call an entry.
 *
 * The decision is one private method both read tools call, because the same
 * query builds list_entries and count_entries and a per-tool copy is how the
 * two would come to disagree about what they are counting.
 *
 * Helpers are closures: Pest shares one global function namespace across the
 * suite, and EntryToolsTest already keeps source-reading helpers of its own.
 */

/** A query that records the section param instead of reaching a database. */
$recordingQuery = static fn (): EntryQuery => new class (Entry::class) extends EntryQuery {
    /** @var list<mixed> */
    public array $sections = [];

    public function section(mixed $value): static {
        $this->sections[] = $value;

        return $this;
    }
};

$scope = static function (EntryQuery $query, ?string $section, bool $includeNested): void {
    (new ReflectionMethod(EntryTools::class, 'scopeToSections'))
        ->invokeArgs((new ReflectionClass(EntryTools::class))->newInstanceWithoutConstructor(), [$query, $section, $includeNested]);
};

$entryToolsSource = static fn (): string => (string) file_get_contents(
    (string) (new ReflectionClass(EntryTools::class))->getFileName(),
);

$toolDescription = static fn (string $method): string => (new ReflectionMethod(EntryTools::class, $method))
    ->getAttributes(McpTool::class)[0]
    ->newInstance()
    ->description;

$parameter = static function (string $method, string $name): ReflectionParameter {
    foreach ((new ReflectionMethod(EntryTools::class, $method))->getParameters() as $parameter) {
        if ($parameter->getName() === $name) {
            return $parameter;
        }
    }

    throw new RuntimeException("{$method} has no {$name} parameter");
};

/** A section whose entry types are fixed, so no service has to answer for them. */
$sectionWith = static fn (string $handle, string ...$types): Section => new class ($handle, $types) extends Section {
    /** @param list<string> $stubTypes */
    public function __construct(string $handle, private readonly array $stubTypes) {
        parent::__construct(['handle' => $handle]);
    }

    /** @return list<EntryType> */
    public function getEntryTypes(): array {
        return array_map(static fn (string $handle): EntryType => new EntryType(['handle' => $handle]), $this->stubTypes);
    }
};

$assertTypeInScope = static function (?string $type, ?string $section, bool $includeNested): void {
    (new ReflectionMethod(EntryTools::class, 'assertTypeInScope'))
        ->invokeArgs((new ReflectionClass(EntryTools::class))->newInstanceWithoutConstructor(), [$type, $section, $includeNested]);
};

describe('the section scope of a read', function () use ($recordingQuery, $scope) {
    // Craft's own switch, not a hand-rolled sectionId filter: '*' means "in
    // any section", and a nested block is in none.
    it('asks Craft for every section when none is named', function () use ($recordingQuery, $scope) {
        $query = $recordingQuery();
        $scope($query, null, false);

        expect($query->sections)->toBe(['*']);
    });

    it('narrows to the handle when a section is named', function () use ($recordingQuery, $scope) {
        $query = $recordingQuery();
        $scope($query, 'pages', false);

        expect($query->sections)->toBe(['pages']);
    });

    // The unfiltered query is what returns blocks, so asking for them means
    // adding nothing rather than adding a param.
    it('leaves the query unscoped when blocks are asked for', function () use ($recordingQuery, $scope) {
        $query = $recordingQuery();
        $scope($query, null, true);

        expect($query->sections)->toBe([]);
    });

    it('keeps a named section, which no block is in, over includeNested', function () use ($recordingQuery, $scope) {
        $query = $recordingQuery();
        $scope($query, 'pages', true);

        expect($query->sections)->toBe(['pages']);
    });
});

// Leaving blocks out turns "list entries of type contentBlock" into zero rows,
// and a bare 0 about a type the install really has is the same confident wrong
// answer assertScope refuses to give about a section it does not have.
describe('an entry type that only Matrix fields use', function () use ($sectionWith, $assertTypeInScope) {
    beforeEach(function () use ($sectionWith) {
        $this->originalApp = Craft::$app;

        // 'page' belongs to a section; 'contentBlock' is a block type only.
        Craft::$app = new class ($sectionWith('pages', 'page'), $sectionWith('home', 'page')) {
            public function __construct(private readonly Section $pages, private readonly Section $home) {
            }

            public function getEntries(): object {
                return new class ($this->pages, $this->home) {
                    public function __construct(private readonly Section $pages, private readonly Section $home) {
                    }

                    /** @return list<Section> */
                    public function getAllSections(): array {
                        return [$this->pages, $this->home];
                    }
                };
            }
        };
    });

    afterEach(function () {
        Craft::$app = $this->originalApp;
    });

    it('is refused, naming the parameter that reaches it', function () use ($assertTypeInScope) {
        expect(fn () => $assertTypeInScope('contentBlock', null, false))
            ->toThrow(ToolCallException::class, "Entry type 'contentBlock' belongs to no section");
    });

    it('says how to read those blocks instead of leaving a zero to explain', function () use ($assertTypeInScope) {
        try {
            $assertTypeInScope('contentBlock', null, false);
        } catch (ToolCallException $refusal) {
            expect($refusal->getMessage())->toContain('Pass includeNested: true');

            return;
        }

        $this->fail('the block-only type was accepted');
    });

    it('passes a type a section really has', function () use ($assertTypeInScope) {
        expect(fn () => $assertTypeInScope('page', null, false))->not->toThrow(ToolCallException::class);
    });

    it('says nothing when the caller asked for blocks', function () use ($assertTypeInScope) {
        expect(fn () => $assertTypeInScope('contentBlock', null, true))->not->toThrow(ToolCallException::class);
    });

    // A named section already excludes blocks by itself, and an entry type that
    // section does not have is assertScope's refusal to make, not this one's.
    it('says nothing when a section is named', function () use ($assertTypeInScope) {
        expect(fn () => $assertTypeInScope('contentBlock', 'pages', false))->not->toThrow(ToolCallException::class);
    });
});

describe('list_entries and count_entries agree about what they are about', function () use ($entryToolsSource, $parameter, $toolDescription) {
    it('scopes both reads through the one helper', function () use ($entryToolsSource) {
        expect(substr_count($entryToolsSource(), '$this->scopeToSections($query, $section, $includeNested);'))->toBe(2);
    });

    it('guards both reads against a type the scope cannot reach', function () use ($entryToolsSource) {
        expect(substr_count($entryToolsSource(), '$this->assertTypeInScope($type, $section, $includeNested);'))->toBe(2);
    });

    it('takes the section out of the filter loop, which would set it twice', function () use ($entryToolsSource) {
        expect($entryToolsSource())->not->toContain("['section' => \$section, 'type' => \$type");
    });

    it('offers the same opt-in, with the same default, on both tools', function (string $method) use ($parameter) {
        $includeNested = $parameter($method, 'includeNested');

        expect((string) $includeNested->getType())->toBe('bool')
            ->and($includeNested->getDefaultValue())->toBeFalse();
    })->with([['listEntries'], ['countEntries']]);

    // An agent reads the schema cold and has no way to know a block is an
    // entry, so the parameter has to say it.
    it('publishes the opt-in as a boolean that explains what a block is', function (string $method) {
        $properties = (new SchemaGenerator(new DocBlockParser()))
            ->generate(new ReflectionMethod(EntryTools::class, $method))['properties'];

        expect($properties['includeNested'])->toMatchArray(['type' => 'boolean', 'default' => false])
            ->and($properties['includeNested']['description'])->toContain('Matrix');
    })->with([['listEntries'], ['countEntries']]);

    it('says in both descriptions that blocks are left out', function (string $method) use ($toolDescription) {
        expect($toolDescription($method))->toContain('includeNested');
    })->with([['listEntries'], ['countEntries']]);

    // "Omit to list across every section" was true of the parameter and false
    // of the answer, which is the sentence that made the mixing look intended.
    it('promises top-level content in the section parameter of both tools', function (string $method) use ($parameter) {
        $schema = $parameter($method, 'section')->getAttributes(Mcp\Capability\Attribute\Schema::class)[0]->newInstance();

        expect($schema->description)->toContain('top-level');
    })->with([['listEntries'], ['countEntries']]);
});
