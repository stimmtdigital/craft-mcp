<?php

declare(strict_types=1);

use stimmt\craft\Mcp\integrations\retour\NotFoundStats;

// Retour keeps a 404's query string unless stripQueryStringFromStats is on,
// and a query string can carry a reset token or an email address. The tool
// answers an agent, so neither the URL nor the referrer may bring it along.
it('returns a 404 URL and its referrer without their query strings', function () {
    $row = NotFoundStats::row([
        'redirectSrcUrl' => '/reset?token=abc123&email=jan%40example.nl',
        'hitCount' => '4',
        'hitLastTime' => null,
        'referrerUrl' => 'https://example.nl/mail?u=jan%40example.nl',
        'handledByRetour' => '0',
        'redirected' => '0',
    ]);

    expect($row['url'])->toBe('/reset')
        ->and($row['referrer'])->toBe('https://example.nl/mail')
        ->and($row['hits'])->toBe(4);
});
