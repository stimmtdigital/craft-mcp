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
