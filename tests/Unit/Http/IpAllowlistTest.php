<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/RealCraft.php';

use stimmt\craft\Mcp\http\IpAllowlist;

/**
 * The Claude connector reaches a site from Anthropic's 160.79.104.0/21, 2048
 * addresses no one lists one by one, so an install that keeps allowedIps
 * filled could only admit it by emptying the list. Ranges fix that.
 */
describe('IpAllowlist', function () {
    it('admits everything when the list is empty, as before', function () {
        expect((new IpAllowlist([]))->allows('203.0.113.7'))->toBeTrue();
    });

    it('still matches exact addresses', function () {
        $list = new IpAllowlist(['203.0.113.7']);

        expect($list->allows('203.0.113.7'))->toBeTrue()
            ->and($list->allows('203.0.113.8'))->toBeFalse();
    });

    it('matches CIDR ranges, such as the one the Claude connector comes from', function () {
        $list = new IpAllowlist(['160.79.104.0/21', '2001:db8::/32']);

        expect($list->allows('160.79.104.1'))->toBeTrue()
            ->and($list->allows('160.79.111.254'))->toBeTrue()
            ->and($list->allows('160.79.112.0'))->toBeFalse()
            ->and($list->allows('2001:db8::1'))->toBeTrue()
            ->and($list->allows('2001:db9::1'))->toBeFalse();
    });

    it('refuses what it cannot parse instead of admitting it', function () {
        expect((new IpAllowlist(['160.79.104.0/21']))->allows('not-an-ip'))->toBeFalse()
            ->and((new IpAllowlist(['garbage']))->allows('160.79.104.1'))->toBeFalse();
    });
});
