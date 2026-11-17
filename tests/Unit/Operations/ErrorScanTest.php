<?php

declare(strict_types=1);

use stimmt\craft\Mcp\operations\ErrorScan;

/**
 * Real files through the real parser: the scan is the same bounded backwards
 * search stimmt_logs_read runs, narrowed to errors in a time window.
 */
beforeEach(function () {
    $this->logs = sys_get_temp_dir() . '/craft-mcp-error-scan-' . bin2hex(random_bytes(4));
    mkdir($this->logs);
    $this->zone = 'Europe/Amsterdam';
    $this->at = static fn (string $modify): string => (new DateTimeImmutable('now', new DateTimeZone('Europe/Amsterdam')))
        ->modify($modify)->format('Y-m-d H:i:s');
});

afterEach(function () {
    array_map(unlink(...), (array) glob($this->logs . '/*'));
    rmdir($this->logs);
});

describe('ErrorScan::run()', function () {
    it('reads the errors in the window from every log, and lists the files it read', function () {
        file_put_contents($this->logs . '/web.log', implode("\n", [
            ($this->at)('-30 hours') . ' [web.ERROR] [application] Too old',
            ($this->at)('-2 hours') . ' [web.WARNING] [application] Not an error',
            ($this->at)('-1 hour') . ' [web.ERROR] [application] Recent web error',
        ]) . "\n");
        file_put_contents($this->logs . '/queue.log', ($this->at)('-10 minutes') . " [queue.ERROR] [application] Recent queue error\n");

        $scan = ErrorScan::run($this->logs, $this->zone, 24, null);

        expect(array_column($scan->groups->groups(), 'message'))->toBe(['Recent queue error', 'Recent web error'])
            ->and($scan->files)->toEqualCanonicalizing(['web.log', 'queue.log'])
            ->and($scan->complete())->toBeTrue();
    });

    it('reads only the log a source names', function () {
        file_put_contents($this->logs . '/web.log', ($this->at)('-1 hour') . " [web.ERROR] [application] Web\n");
        file_put_contents($this->logs . '/queue.log', ($this->at)('-1 hour') . " [queue.ERROR] [application] Queue\n");

        $scan = ErrorScan::run($this->logs, $this->zone, 24, 'queue');

        expect(array_column($scan->groups->groups(), 'message'))->toBe(['Queue'])->and($scan->files)->toBe(['queue.log']);
    });

    // Fewer entries than the cap means the files were read to their scan
    // depth, even when every error found is inside the window.
    it('is complete when the cap did not fill, even with every error inside the window', function () {
        file_put_contents($this->logs . '/web.log', ($this->at)('-1 minute') . " [web.ERROR] [application] Only one\n");

        expect(ErrorScan::run($this->logs, $this->zone, 24, null)->complete())->toBeTrue();
    });

    // When the cap fills with errors that are all inside the window, older
    // ones in it were never read, so every count is a floor.
    it('says it is incomplete when the entry cap fills inside the window', function () {
        $line = ($this->at)('-1 minute') . " [web.ERROR] [application] Flood\n";
        file_put_contents($this->logs . '/web.log', str_repeat($line, ErrorScan::MAX_ENTRIES + 10));

        $scan = ErrorScan::run($this->logs, $this->zone, 24, null);

        expect($scan->entries)->toHaveCount(ErrorScan::MAX_ENTRIES)->and($scan->complete())->toBeFalse();
    });

    it('is complete when the cap filled but reached back past the window', function () {
        $old = ($this->at)('-48 hours') . " [web.ERROR] [application] Old\n";
        $new = ($this->at)('-1 minute') . " [web.ERROR] [application] New\n";
        file_put_contents($this->logs . '/web.log', str_repeat($old, ErrorScan::MAX_ENTRIES) . $new);

        expect(ErrorScan::run($this->logs, $this->zone, 24, null)->complete())->toBeTrue();
    });
});
