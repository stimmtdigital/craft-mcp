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
final class Expectation {
    /**
     * @return array<string, array{steps: list<string>, profiles?: list<string>, status: string, contains?: string, why: string, found: string}>
     */
    public static function all(): array {
        return [
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
