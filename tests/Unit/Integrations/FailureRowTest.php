<?php

declare(strict_types=1);

use stimmt\craft\Mcp\integrations\formie\FailureRow;

$attempt = static fn (array $overrides = []): array => [
    'id' => '41',
    'submissionId' => '1207',
    'formId' => '3',
    'binding' => 'hubspot',
    'step' => 'integration',
    'status' => 'failed',
    'result' => json_encode([
        'status' => 'failed',
        'message' => 'Contact jane@example.com rejected: property "phone" invalid',
        'code' => 'http_400',
        'retryable' => true,
        'diagnostics' => ['request' => ['email' => 'jane@example.com', 'phone' => '0612345678']],
        'outputs' => [],
    ]),
    'completedAt' => '2026-11-16 09:30:00',
    'dateUpdated' => '2026-11-16 09:30:05',
    ...$overrides,
];

$shape = static fn (array $row): array => FailureRow::shape(
    $row,
    [3 => 'contact'],
    ['hubspot' => 'HubSpot CRM', 'notification:abc-uid' => 'Admin notification'],
);

describe('FailureRow::shape()', function () use ($attempt, $shape) {
    it('shapes a failed integration attempt into the row the tool returns', function () use ($attempt, $shape) {
        expect($shape($attempt()))->toBe([
            'submissionId' => 1207,
            'form' => 'contact',
            'integration' => 'HubSpot CRM',
            'kind' => 'integration',
            'status' => 'failed',
            'error' => 'Contact [email] rejected: property "phone" invalid',
            'dateFailed' => '2026-11-16T09:30:00+00:00',
            'retryable' => true,
        ]);
    });

    it('never carries the stored diagnostics, which hold the submission', function () use ($attempt, $shape) {
        $encoded = (string) json_encode($shape($attempt()));

        expect($encoded)->not->toContain('0612345678')
            ->and($encoded)->not->toContain('jane@example.com')
            ->and($encoded)->not->toContain('diagnostics');
    });

    it('labels an email notification by its name and kind', function () use ($attempt, $shape) {
        $row = $shape($attempt(['binding' => 'notification:abc-uid', 'step' => 'notification']));

        expect($row['integration'])->toBe('Admin notification')
            ->and($row['kind'])->toBe('notification');
    });

    it('falls back to the raw binding when the integration was since deleted', function () use ($attempt, $shape) {
        expect($shape($attempt(['binding' => 'oldWebhook']))['integration'])->toBe('oldWebhook');
    });

    it('reads a retired status as the failure Formie now calls it', function (string $status) use ($attempt, $shape) {
        expect($shape($attempt(['status' => $status]))['status'])->toBe('failed');
    })->with([['rejected'], ['unknown']]);

    it('uses the result code when the provider gave no message', function () use ($attempt, $shape) {
        $result = json_encode(['status' => 'failed', 'message' => '', 'code' => 'http_503']);
        $row = $shape($attempt(['result' => $result]));

        expect($row['error'])->toBe('http_503');
    });

    it('points to the control panel when Formie stored a truncated result', function () use ($attempt, $shape) {
        $row = $shape($attempt(['result' => json_encode(['truncated' => true, 'preview' => '{"message":"jane@'])]));

        expect($row['error'])
            ->toBe('Formie stored a truncated result; open the submission in the control panel for detail.');
    });

    it('says so when there is no result at all', function () use ($attempt, $shape) {
        expect($shape($attempt(['result' => null]))['error'])->toBe('No result recorded.');
    });

    it('dates the failure by its last update when it never completed', function () use ($attempt, $shape) {
        expect($shape($attempt(['completedAt' => null]))['dateFailed'])->toBe('2026-11-16T09:30:05+00:00');
    });

    it('leaves the form null for a form that no longer exists', function () use ($attempt, $shape) {
        expect($shape($attempt(['formId' => '99']))['form'])->toBeNull();
    });
});
