<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/CraftStub.php';

use stimmt\craft\Mcp\tools\DebugTools;
use yii\base\Event;

describe('DebugTools::listEventHandlers() class events', function () {
    afterEach(function () {
        Event::off('Acme\\Widget', 'onFrobnicate');
    });

    it('labels class-level handlers with the real class and event, not swapped', function () {
        Event::on('Acme\\Widget', 'onFrobnicate', fn () => null);

        $result = (new DebugTools())->listEventHandlers('onFrobnicate');
        $events = $result['classEvents']['events'];

        expect($events)->toHaveKey('Acme\\Widget::onFrobnicate')
            ->and($events['Acme\\Widget::onFrobnicate']['class'])->toBe('Acme\\Widget')
            ->and($events['Acme\\Widget::onFrobnicate']['event'])->toBe('onFrobnicate')
            ->and($events['Acme\\Widget::onFrobnicate']['count'])->toBe(1);
    });
});

// Behavioural coverage for get_deprecations needs a booted Craft (it reads the
// log path and the deprecationerrors table), so the log-reading contract is
// pinned at the source level instead. Both defects it guards were invisible in
// the response: the tool answered "none" from a file that does not exist,
// parsed by a regex that could not have matched a Craft log line anyway.
describe('DebugTools::getDeprecations() log source', function () {
    $source = static fn (): string => (string) file_get_contents(
        (new ReflectionClass(DebugTools::class))->getFileName(),
    );

    it('never reads an unrotated web.log', function () use ($source) {
        expect($source())->not->toContain("'/web.log'");
    });

    it('goes through the parser that knows about rotation and the real line format', function () use ($source) {
        expect($source())->toContain('new Parser(Craft::$app->getPath()->getLogPath())')
            ->and($source())->toContain('$parser->newest(');
    });

    it('does not parse log lines with a regex of its own', function () use ($source) {
        expect($source())->not->toContain('preg_match(\'/^\[');
    });
});
