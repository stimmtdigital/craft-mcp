<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Smoke;

/**
 * The register of defects we know about and have not fixed yet.
 *
 * WHY this exists rather than a comment or a ticket: an expectation here is
 * self-clearing. The harness holds each one to its exact symptom, so a defect
 * that starts behaving FAILS the run with "this now works, delete the
 * expectation". A fix announces itself instead of going quiet, and a fix nobody
 * intended cannot pass unnoticed either.
 *
 * Every finding worth remembering gets an entry. The rule is one entry per
 * defect, naming the steps that provoke it, the status it produces and enough
 * of the message to be sure it is the same defect rather than a lookalike.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Expectations {
    /**
     * @return array<string, array{steps: list<string>, status: string, contains?: string, why: string, found: string}>
     */
    public static function all(): array {
        return [
            'graphql-needs-a-web-request' => [
                'steps' => ['query_graphql', 'execute_graphql'],
                'status' => 'tool-error',
                'contains' => 'getHeaders()',
                'why' => 'Craft\'s GraphQL path asks the request for headers. Over stdio the '
                    . 'request is a console request, which has no such method, so both GraphQL '
                    . 'tools fail on the transport nearly every user runs.',
                'found' => '2026-08-13',
            ],
        ];
    }

    /**
     * The expectation covering a step, if any.
     *
     * @return array{id: string, status: string, contains?: string}|null
     */
    public static function covering(string $step): ?array {
        foreach (self::all() as $id => $expectation) {
            if (in_array($step, $expectation['steps'], true)) {
                return [
                    'id' => $id,
                    'status' => $expectation['status'],
                    'contains' => $expectation['contains'] ?? null,
                ];
            }
        }

        return null;
    }
}
