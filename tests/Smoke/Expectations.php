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
 * A defect is only universal if it is proven universal. An entry may name the
 * profiles it applies to, and then it is held against those and required to be
 * absent everywhere else: both GraphQL tools fail on stdio and work over HTTP,
 * and a register that could not say so would have to either forgive a real
 * failure over HTTP or demand a fictional one.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Expectations {
    /**
     * @return array<string, array{steps: list<string>, profiles?: list<string>, status: string, contains?: string, why: string, found: string}>
     */
    public static function all(): array {
        return [
            'graphql-needs-a-web-request' => [
                'steps' => ['query_graphql', 'execute_graphql'],
                'profiles' => ['stdio-full'],
                'status' => 'tool-error',
                'contains' => 'getHeaders()',
                'why' => 'Craft\'s GraphQL path asks the request for headers. Over stdio the '
                    . 'request is a console request, which has no such method, so both GraphQL '
                    . 'tools fail on the transport nearly every user runs. Over HTTP the request '
                    . 'is a real web request and both tools answer, which is why this is pinned '
                    . 'to the stdio profile rather than to the tools.',
                'found' => '2026-08-13',
            ],
            'http-notification-destroys-its-own-response' => [
                'steps' => ['reload_mcp'],
                'profiles' => ['http-full', 'http-content', 'http-readonly'],
                'status' => 'crashed',
                'contains' => 'SSE frames escaped the response',
                'why' => 'A tool that notifies suspends a fiber. Over HTTP the SDK answers that '
                    . 'by switching the response to SSE and echoing the frames from a callback '
                    . 'that only fires when the body is stringified, which happens after the '
                    . 'controller has already closed its output buffer. The echo therefore '
                    . 'reaches the SAPI directly: PHP sends its own default headers (200 '
                    . 'text/html), Craft\'s own headers and the text/event-stream content type '
                    . 'never arrive, and Yii then dies on HeadersAlreadySentException pointing '
                    . 'at the SDK\'s flush. The client is handed event-stream framing labelled '
                    . 'text/html, which is unreadable either way it tries. reload_mcp notifies '
                    . 'unconditionally, so it reproduces on every HTTP profile; on stdio the '
                    . 'same tool answers normally, which is why this entry names its profiles. '
                    . 'Mechanism in docs/superpowers/plans/plan-transport.md section 1; the '
                    . 'observed symptom above is worse than the empty body predicted there.',
                'found' => '2026-08-17',
            ],
        ];
    }

    /**
     * The expectation covering a step on a profile, if any.
     *
     * @return array{id: string, status: string, contains?: string}|null
     */
    public static function covering(string $step, string $profile): ?array {
        foreach (self::all() as $id => $expectation) {
            $profiles = $expectation['profiles'] ?? null;
            if (!in_array($step, $expectation['steps'], true)) {
                continue;
            }

            if ($profiles !== null && !in_array($profile, $profiles, true)) {
                continue;
            }

            return [
                'id' => $id,
                'status' => $expectation['status'],
                'contains' => $expectation['contains'] ?? null,
            ];
        }

        return null;
    }
}
