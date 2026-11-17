<?php

declare(strict_types=1);

use stimmt\craft\Mcp\integrations\ErrorText;

describe('ErrorText::clean()', function () {
    it('scrubs email addresses a provider echoed back', function () {
        expect(ErrorText::clean('Contact jane.doe+crm@example.co.uk already exists'))
            ->toBe('Contact [email] already exists');
    });

    it('scrubs phone numbers in the usual spellings', function (string $phone) {
        expect(ErrorText::clean("Invalid phone {$phone} for lead"))->toBe('Invalid phone [phone] for lead');
    })->with([['+31 6 12345678'], ['0612345678'], ['(020) 555-1234'], ['+1-202-555-0143']]);

    it('keeps the numbers that explain the failure', function () {
        $message = 'HTTP 422 at 2026-11-17 10:15:00 for record 12345, order 1234567, retry after 3600 seconds';

        expect(ErrorText::clean($message))->toBe($message);
    });

    it('truncates to 500 characters, marking that it did', function () {
        $clean = ErrorText::clean(str_repeat('x', 800));

        expect(mb_strlen($clean))->toBe(501)
            ->and($clean)->toEndWith('…');
    });

    it('leaves a message at the limit whole', function () {
        expect(ErrorText::clean(str_repeat('y', 500)))->toBe(str_repeat('y', 500));
    });

    it('collapses whitespace so a stack of lines reads as one message', function () {
        expect(ErrorText::clean("Bad request:\n\n  field   missing"))->toBe('Bad request: field missing');
    });
});
