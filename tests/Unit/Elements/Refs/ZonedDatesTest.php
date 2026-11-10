<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../Fixtures/RealCraft.php';

use craft\fields\Date;
use craft\fields\PlainText;
use stimmt\craft\Mcp\elements\Context;
use stimmt\craft\Mcp\elements\InvalidInput;
use stimmt\craft\Mcp\elements\refs\ZonedDates;

/**
 * #75: a Date field with "Show time zone" keeps a zone name next to its
 * instant, and an ISO string with an offset cannot carry one (several zones
 * share an offset with different daylight saving rules). get_entry returned
 * such a string and Craft stored it in the system zone, so writing back what
 * was read moved the event. Both directions now use Craft's own form shape,
 * {date, timezone}, which it stores with the zone intact.
 */
describe('ZonedDates', function () {
    beforeEach(function () {
        $this->zoned = new Date(['handle' => 'startDate', 'showTime' => true, 'showTimeZone' => true]);
        $this->translator = new ZonedDates();
    });

    it('handles only date fields that show a time zone', function () {
        expect($this->translator->handles($this->zoned))->toBeTrue()
            ->and($this->translator->handles(new Date(['handle' => 'd', 'showTime' => true])))->toBeFalse()
            ->and($this->translator->handles(new PlainText(['handle' => 'p'])))->toBeFalse();
    });

    it('reads a date in its own zone, not as an offset', function () {
        $value = new DateTime('2026-11-12 10:00:00', new DateTimeZone('Australia/Perth'));

        expect(ZonedDates::read($value))->toBe(['date' => '2026-11-12 10:00:00', 'timezone' => 'Australia/Perth']);
    });

    // The shape read is the shape written: passing it straight back changes nothing.
    it('passes the read shape back through unchanged and without a warning', function () {
        $context = new Context();
        $value = ['date' => '2026-04-05 09:00:00', 'timezone' => 'Australia/Adelaide'];

        expect($this->translator->toIds($this->zoned, $value, $context))->toBe($value)
            ->and($context->warnings())->toBe([]);
    });

    it('warns that a plain date-time string loses the zone', function () {
        $context = new Context();

        expect($this->translator->toIds($this->zoned, '2026-11-12T10:00:00+08:00', $context))->toBe('2026-11-12T10:00:00+08:00')
            ->and($context->warnings())->toHaveCount(1)
            ->and($context->warnings()[0]->message)->toContain('timezone');
    });

    it('refuses a time zone name PHP does not know', function () {
        expect(fn () => $this->translator->toIds($this->zoned, ['date' => '2026-11-12 10:00:00', 'timezone' => 'Mars/Olympus'], new Context()))
            ->toThrow(InvalidInput::class, 'Mars/Olympus');
    });
});
