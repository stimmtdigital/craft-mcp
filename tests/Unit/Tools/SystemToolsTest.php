<?php

declare(strict_types=1);

use stimmt\craft\Mcp\logging\Parser;
use stimmt\craft\Mcp\tools\SystemTools;

// read_logs and get_last_error need a booted Craft to reach the log path, so
// the contract that broke them is pinned at the source level. limit used to be
// multiplied into a line count and the level filter applied afterwards, which
// turned a small limit into a shallow search and reported an install with
// thousands of errors as clean.
describe('SystemTools::readLogs() search depth', function () {
    $source = static fn (): string => (string) file_get_contents(
        (new ReflectionClass(SystemTools::class))->getFileName(),
    );

    it('never turns limit into a number of lines to read', function () use ($source) {
        expect($source())->not->toContain('$limit * 2');
    });

    it('asks the parser for the newest matches rather than filtering a window', function () use ($source) {
        expect($source())->toContain('$parser->newest(');
    });
});

describe('SystemTools::getLastError()', function () {
    it('says how deep it looked when it finds nothing', function () {
        // "No errors found" on its own reads as a statement about the install.
        // During an incident that is the one sentence that must not be vague.
        $source = (string) file_get_contents((new ReflectionClass(SystemTools::class))->getFileName());

        expect($source)->toContain('Parser::scanDepth()')
            ->and(Parser::scanDepth())->toContain('per file');
    });
});

// get_config needs a booted Craft to read the config service, so the posture
// is pinned at the source level. The value leaving the tool goes through the
// one redaction rule, and the db category lists its credential settings rather
// than omitting them, which is what let the keyed read and the category read
// disagree about whether the install even has a database password.
describe('SystemTools::getConfig() redaction', function () {
    $source = static fn (): string => (string) file_get_contents(
        (new ReflectionClass(SystemTools::class))->getFileName(),
    );

    it('sends every value it returns through the shared rule', function () use ($source) {
        expect($source())->toContain("'value' => Secrets::conceal(\$key, \$value)");
    });

    it('does not carry a sensitivity list of its own', function () use ($source) {
        expect($source())->not->toContain('securityKey');
    });

    it('names the database credentials in the category read', function () use ($source) {
        expect($source())->toContain("'password' => \$config->getDb()->password")
            ->and($source())->toContain("'dsn' => \$config->getDb()->dsn");
    });

    it('tells a caller in the tool description that credentials come back redacted', function () {
        $tool = (new ReflectionMethod(SystemTools::class, 'getConfig'))
            ->getAttributes(Mcp\Capability\Attribute\McpTool::class)[0]
            ->newInstance();

        expect($tool->description)->toContain('redacted');
    });
});
