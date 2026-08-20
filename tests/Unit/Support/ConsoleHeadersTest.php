<?php

declare(strict_types=1);

use stimmt\craft\Mcp\support\ConsoleHeaders;
use yii\base\Component;
use yii\web\HeaderCollection;

/**
 * The shim is only worth anything if Yii actually reaches it, so the test
 * attaches it to a plain Component and calls the method through the same
 * __call() path that throws today. Asserting the class has a method would prove
 * nothing about the dispatch that was broken.
 */
it('answers getHeaders through Yii behaviour dispatch', function (): void {
    $component = new Component();
    $component->attachBehavior(ConsoleHeaders::NAME, new ConsoleHeaders());

    // Called dynamically because the whole point is a method the component does
    // not declare: a static call would be a compile-time lie about the seam.
    $headers = call_user_func([$component, 'getHeaders']);

    expect($headers)->toBeInstanceOf(HeaderCollection::class)
        ->and($headers->count())->toBe(0);
});

it('reads a missing header as null, the same as a web request that did not send it', function (): void {
    $headers = (new ConsoleHeaders())->getHeaders();

    expect($headers->get('X-Anything'))->toBeNull();
});

it('hands back the same collection each time so a listener can rely on identity', function (): void {
    $behaviour = new ConsoleHeaders();

    expect($behaviour->getHeaders())->toBe($behaviour->getHeaders());
});

it('is attached by the stdio transport seam and nowhere else', function (): void {
    $factory = file_get_contents(dirname(__DIR__, 3) . '/src/services/ServerFactory.php');

    expect($factory)->toContain('ConsoleHeaders::NAME, new ConsoleHeaders()');

    $createTransport = substr($factory, (int) strpos($factory, 'public function createTransport'));
    $createHttpTransport = substr($factory, (int) strpos($factory, 'public function createHttpTransport'));

    expect(substr($createTransport, 0, strlen($createTransport) - strlen($createHttpTransport)))
        ->toContain('attachBehavior')
        ->and($createHttpTransport)->not->toContain('attachBehavior');
});
