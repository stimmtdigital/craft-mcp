<?php

declare(strict_types=1);

use Mcp\Capability\Registry\ElementReference;
use Mcp\Capability\Registry\PromptReference;
use Mcp\Capability\Registry\ReferenceHandlerInterface;
use Mcp\Capability\Registry\ToolReference;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Prompt;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\Tool;
use stimmt\craft\Mcp\enums\ResponseFormat;
use stimmt\craft\Mcp\support\Palette;
use stimmt\craft\Mcp\support\Presenter;
use stimmt\craft\Mcp\support\Renderer;

/**
 * A reference handler that returns whatever the test hands it, standing in for
 * the SDK's reflection-based one.
 */
function returning(mixed $result): ReferenceHandlerInterface {
    return new class ($result) implements ReferenceHandlerInterface {
        public function __construct(private readonly mixed $result) {
        }

        public function handle(ElementReference $reference, array $arguments): mixed {
            return $this->result;
        }
    };
}

function toolReference(bool $declaresOutput = false): ToolReference {
    $properties = $declaresOutput
        ? ['output' => ['type' => 'string', 'enum' => array_column(ResponseFormat::cases(), 'value')]]
        : [];

    return new ToolReference(
        new Tool('demo', null, ['type' => 'object', 'properties' => $properties], 'Demo', null),
        static fn (): array => [],
    );
}

function present(mixed $result, array $arguments = [], bool $declaresOutput = false): mixed {
    $presenter = new Presenter(returning($result), new Renderer(new Palette(false)));

    return $presenter->handle(toolReference($declaresOutput), $arguments);
}

describe('Presenter payload duplication', function () {
    it('carries an array payload exactly once, with no structuredContent', function () {
        $result = present(['count' => 1, 'sites' => [['id' => 1]]]);

        expect($result)->toBeInstanceOf(CallToolResult::class)
            ->and($result->structuredContent)->toBeNull()
            ->and($result->content)->toHaveCount(1);

        $wire = $result->jsonSerialize();

        expect($wire)->not->toHaveKey('structuredContent');
    });

    it('encodes the array into the single content item without losing a field', function () {
        $result = present(['count' => 1, 'handle' => 'default']);

        $decoded = json_decode($result->content[0]->text, true);

        expect($decoded)->toBe(['count' => 1, 'handle' => 'default']);
    });
});

describe('Presenter pass-through', function () {
    it('leaves prompt and resource references alone', function () {
        $presenter = new Presenter(returning(['raw' => true]), new Renderer(new Palette(false)));
        $reference = new PromptReference(new Prompt('demo', null, null, null), static fn (): array => []);

        expect($presenter->handle($reference, []))->toBe(['raw' => true]);
    });

    it('leaves a tool that already built its own result alone', function () {
        $own = CallToolResult::error([new TextContent('boom')]);

        expect(present($own))->toBe($own);
    });

    it('wraps a single Content return without adding structuredContent', function () {
        $result = present(new TextContent('> $x\n= 1'));

        expect($result)->toBeInstanceOf(CallToolResult::class)
            ->and($result->structuredContent)->toBeNull()
            ->and($result->content)->toHaveCount(1)
            ->and($result->content[0]->text)->toBe('> $x\n= 1');
    });

    it('keeps a mixed content list intact', function () {
        $result = present([new TextContent('a'), new ImageContent('data', 'image/png')]);

        expect($result->content)->toHaveCount(2)
            ->and($result->structuredContent)->toBeNull();
    });
});

describe('Presenter text output', function () {
    it('renders the payload as text when the tool declares output and the caller asks', function () {
        $result = present(
            ['count' => 1, 'handle' => 'default'],
            ['output' => 'text'],
            declaresOutput: true,
        );

        expect($result->content[0]->text)->toBe("count:  1\nhandle: default")
            ->and($result->structuredContent)->toBeNull();
    });

    it('stays structured when the caller asks for structured', function () {
        $result = present(
            ['count' => 1],
            ['output' => 'structured'],
            declaresOutput: true,
        );

        expect(json_decode($result->content[0]->text, true))->toBe(['count' => 1]);
    });

    it('never guesses for a tool that does not declare the parameter', function () {
        $result = present(['count' => 1], ['output' => 'text']);

        expect(json_decode($result->content[0]->text, true))->toBe(['count' => 1]);
    });

    it('ignores an output value that is not a response format', function () {
        $result = present(['count' => 1], ['output' => 'dump'], declaresOutput: true);

        expect(json_decode($result->content[0]->text, true))->toBe(['count' => 1]);
    });

    it('accepts an already-cast enum argument', function () {
        $result = present(
            ['count' => 1],
            ['output' => ResponseFormat::TEXT],
            declaresOutput: true,
        );

        expect($result->content[0]->text)->toBe('count: 1');
    });
});
