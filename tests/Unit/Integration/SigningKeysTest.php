<?php

declare(strict_types=1);

use stimmt\craft\Mcp\integration\SigningKeys;

/**
 * A provisioner over stand-ins, recording whether it generated. `$hasKeys`
 * answers in order, so "missing, then present after generating" is two values.
 *
 * @param list<bool> $hasKeys
 */
function signingKeys(array $hasKeys, bool $configured = false, bool $anyFile = false, ?Throwable $fails = null, ?bool &$generated = null): SigningKeys {
    $generated = false;

    return new SigningKeys(
        hasKeys: static function () use (&$hasKeys): bool {
            return array_shift($hasKeys) ?? false;
        },
        configured: static fn (): bool => $configured,
        anyFileExists: static fn (): bool => $anyFile,
        generate: static function () use ($fails, &$generated): void {
            $generated = true;
            if ($fails !== null) {
                throw $fails;
            }
        },
    );
}

describe('SigningKeys::ensure()', function () {
    it('leaves a working pair alone', function () {
        $keys = signingKeys([true], generated: $generated);

        expect($keys->ensure())->toBe(SigningKeys::PRESENT)
            ->and($generated)->toBeFalse();
    });

    it('generates a pair when there is none, and confirms Craft can load it', function () {
        $keys = signingKeys([false, true], generated: $generated);

        expect($keys->ensure())->toBe(SigningKeys::GENERATED)
            ->and($generated)->toBeTrue();
    });

    // Configured keys win over files in Craft, so a generated pair would be
    // ignored while the real problem, a broken setting, stayed hidden.
    it('does not generate over configured keys that fail to load', function () {
        $keys = signingKeys([false], configured: true, generated: $generated);

        expect($keys->ensure())->toBe(SigningKeys::CONFIGURED_INVALID)
            ->and($generated)->toBeFalse();
    });

    // Replacing keys invalidates every token signed with the old pair, which
    // logs out every connected client without a word.
    it('never replaces a half-present pair', function () {
        $keys = signingKeys([false], anyFile: true, generated: $generated);

        expect($keys->ensure())->toBe(SigningKeys::PARTIAL)
            ->and($generated)->toBeFalse();
    });

    it('reports a failure instead of throwing, so a deploy is not stopped by it', function () {
        expect(signingKeys([false], fails: new RuntimeException('openssl unavailable'))->ensure())->toBe(SigningKeys::FAILED)
            ->and(signingKeys([false, false])->ensure())->toBe(SigningKeys::FAILED);
    });

    it('describes every outcome with what to do', function (string $outcome, string $needle) {
        expect(SigningKeys::describe($outcome))->toContain($needle);
    })->with([
        [SigningKeys::PRESENT, 'already in place'],
        [SigningKeys::GENERATED, 'same pair'],
        [SigningKeys::CONFIGURED_INVALID, 'privateKey/publicKey'],
        [SigningKeys::PARTIAL, '--force'],
        [SigningKeys::FAILED, 'oauth/generate-keys'],
    ]);
});
