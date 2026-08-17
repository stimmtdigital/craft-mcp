<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Smoke;

use Throwable;

/**
 * Wire level smoke harness.
 *
 *   ddev exec php backend/plugins/craft-mcp/tests/Smoke/run.php --baseline
 *   ddev exec php backend/plugins/craft-mcp/tests/Smoke/run.php
 *
 * The first records a snapshot, the second compares against it and exits
 * non-zero on any drift. Run it before and after each refactor phase: the whole
 * promise of those phases is that structure changes and behaviour does not, and
 * this is the only thing in the repo that can hold us to that.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
require_once __DIR__ . '/Client.php';
require_once __DIR__ . '/Shape.php';
require_once __DIR__ . '/Assert.php';
require_once __DIR__ . '/Expectations.php';
require_once __DIR__ . '/Plan.php';

$options = getopt('', ['baseline', 'heavy', 'server:', 'out:']);
$writeBaseline = array_key_exists('baseline', $options);
$includeHeavy = array_key_exists('heavy', $options);
$serverCommand = is_string($options['server'] ?? null)
    ? $options['server']
    : 'php ' . dirname(__DIR__, 2) . '/bin/mcp-server';
$snapshotPath = is_string($options['out'] ?? null)
    ? $options['out']
    : __DIR__ . '/baseline/stdio.json';

$runner = new Runner($serverCommand, $includeHeavy);
$snapshot = $runner->run();

if ($writeBaseline) {
    $directory = dirname($snapshotPath);
    if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
        fwrite(STDERR, "Could not create {$directory}\n");

        exit(1);
    }

    file_put_contents($snapshotPath, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    fwrite(STDOUT, Report::summary($snapshot));
    fwrite(STDOUT, "Baseline written to {$snapshotPath}\n");

    exit(Report::failureCount($snapshot) > 0 ? 1 : 0);
}

if (!is_file($snapshotPath)) {
    fwrite(STDERR, "No baseline at {$snapshotPath}. Record one with --baseline first.\n");

    exit(2);
}

$baseline = json_decode((string) file_get_contents($snapshotPath), true);
if (!is_array($baseline)) {
    fwrite(STDERR, "Baseline at {$snapshotPath} is not readable JSON.\n");

    exit(2);
}

$differences = Report::diff($baseline, $snapshot);

fwrite(STDOUT, Report::summary($snapshot));

if ($differences === []) {
    fwrite(STDOUT, "No drift against the baseline.\n");

    exit(Report::failureCount($snapshot) > 0 ? 1 : 0);
}

fwrite(STDOUT, "\nDrift against the baseline (" . count($differences) . "):\n");
foreach ($differences as $difference) {
    fwrite(STDOUT, "  {$difference}\n");
}

exit(1);

/**
 * Executes the plan against a live server.
 */
final class Runner {
    /** @var array<string, mixed> */
    private array $captured = [];

    public function __construct(
        private readonly string $serverCommand,
        private readonly bool $includeHeavy,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(): array {
        $client = new Client($this->serverCommand);
        $client->start();

        try {
            $initialize = $client->initialize();
            $tools = $this->toolCatalogue($client);
            $steps = $this->steps($client, $tools);
        } finally {
            $stderr = $client->stderrOutput();
            $client->stop();
        }

        return [
            'protocolVersion' => $initialize['protocolVersion'] ?? null,
            'capabilities' => $initialize['capabilities'] ?? [],
            'toolCount' => count($tools),
            'tools' => $tools,
            'uncovered' => $this->uncovered($tools, $steps),
            'steps' => $steps,
            'stderr' => trim($stderr) === '' ? null : '<present>',
        ];
    }

    /**
     * The catalogue is recorded verbatim, not shaped. A tool name, description
     * or schema changing by accident is exactly the regression we cannot afford,
     * and unlike a payload none of it is volatile.
     *
     * @return array<string, mixed>
     */
    private function toolCatalogue(Client $client): array {
        $result = $client->request('tools/list', []);
        $tools = is_array($result['tools'] ?? null) ? $result['tools'] : [];

        $catalogue = [];
        foreach ($tools as $tool) {
            if (!is_array($tool) || !is_string($tool['name'] ?? null)) {
                continue;
            }

            $catalogue[$tool['name']] = [
                'description' => $tool['description'] ?? null,
                'annotations' => $tool['annotations'] ?? null,
                'inputSchema' => $tool['inputSchema'] ?? null,
            ];
        }

        ksort($catalogue);

        return $catalogue;
    }

    /**
     * @param array<string, mixed> $tools
     * @return array<string, mixed>
     */
    private function steps(Client $client, array $tools): array {
        $runId = 'r' . substr(sha1((string) getmypid() . microtime()), 0, 8);
        $results = [];

        foreach (Plan::steps($runId) as $step) {
            $tool = (string) $step['tool'];
            $name = is_string($step['name'] ?? null) ? $step['name'] : $tool;

            $skip = $this->skipReason($step, $tools);
            if ($skip !== null) {
                $results[$name] = ['status' => 'skipped', 'reason' => $skip];

                continue;
            }

            $results[$name] = $this->judge($name, $tool, $this->call($client, $step));
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, mixed> $tools
     */
    private function skipReason(array $step, array $tools): ?string {
        if (is_string($step['skip'] ?? null) && !($this->includeHeavy && ($step['tag'] ?? null) === Plan::TAG_HEAVY)) {
            return $step['skip'];
        }

        if (!array_key_exists((string) $step['tool'], $tools)) {
            return 'not advertised by this connection';
        }

        return null;
    }

    /**
     * Holds a result against the register of known defects.
     *
     * A step with no expectation must be ok. A step with one must still be
     * broken in exactly the way recorded, so a defect that quietly starts
     * working, or breaks differently, both surface as failures.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function judge(string $name, string $tool, array $result): array {
        if ($result['status'] === 'skipped') {
            return $result;
        }

        $expectation = Expectations::covering($name) ?? Expectations::covering($tool);

        if ($expectation === null) {
            if ($result['status'] !== 'ok') {
                $result['unexpected'] = 'expected ok';
            }

            return $result;
        }

        $id = $expectation['id'];
        $result['expected'] = $id;

        if ($result['status'] === 'ok') {
            $result['unexpected'] = "{$id} no longer reproduces: delete it from Expectations";

            return $result;
        }

        if ($result['status'] !== $expectation['status']) {
            $result['unexpected'] = "{$id} changed symptom, now {$result['status']}";

            return $result;
        }

        $contains = $expectation['contains'] ?? null;
        if (is_string($contains) && !str_contains((string) ($result['message'] ?? ''), $contains)) {
            $result['unexpected'] = "{$id} changed message";
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
    private function call(Client $client, array $step): array {
        $arguments = $this->resolve(is_array($step['args'] ?? null) ? $step['args'] : []);
        if ($arguments === null) {
            return ['status' => 'skipped', 'reason' => 'an earlier step did not produce the value this one needs'];
        }

        try {
            $envelope = $client->callTool((string) $step['tool'], $arguments);
        } catch (Throwable $exception) {
            return ['status' => 'crashed', 'error' => $exception->getMessage()];
        }

        if (isset($envelope['error']) && is_array($envelope['error'])) {
            return [
                'status' => 'protocol-error',
                'code' => $envelope['error']['code'] ?? null,
                'message' => $envelope['error']['message'] ?? null,
            ];
        }

        $result = is_array($envelope['result'] ?? null) ? $envelope['result'] : [];
        $payload = $this->payload($result);

        if (is_array($payload) && is_array($step['capture'] ?? null)) {
            $this->capture($step['capture'], $payload);
        }

        if (($result['isError'] ?? false) === true) {
            return ['status' => 'tool-error', 'message' => $this->text($result)];
        }

        $rules = is_array($step['assert'] ?? null) ? $step['assert'] : [];
        $failures = $rules === [] ? [] : Assert::check($payload, $rules);

        if ($failures !== []) {
            return ['status' => 'assert-failed', 'failures' => $failures, 'shape' => Shape::of($payload)];
        }

        return ['status' => 'ok', 'shape' => Shape::of($payload)];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function text(array $result): string {
        $content = is_array($result['content'] ?? null) ? $result['content'] : [];
        $first = is_array($content[0] ?? null) ? $content[0] : [];
        $text = is_string($first['text'] ?? null) ? $first['text'] : '';

        return strlen($text) > 200 ? substr($text, 0, 197) . '...' : $text;
    }

    /**
     * Tool results arrive as text content carrying JSON. The harness compares
     * the decoded payload, so a formatting change in the envelope does not read
     * as a behaviour change, and a real change in the data does.
     *
     * @param array<string, mixed> $result
     */
    private function payload(array $result): mixed {
        $content = is_array($result['content'] ?? null) ? $result['content'] : [];
        $first = is_array($content[0] ?? null) ? $content[0] : [];
        $text = $first['text'] ?? null;

        if (!is_string($text)) {
            return $result;
        }

        $decoded = json_decode($text, true);

        return $decoded === null && trim($text) !== 'null' ? '<text>' : $decoded;
    }

    /**
     * @param array<string, string> $rules
     */
    private function capture(array $rules, mixed $payload): void {
        foreach ($rules as $variable => $path) {
            $value = $this->pluck($payload, explode('.', $path));
            if ($value !== null) {
                $this->captured[$variable] = $value;
            }
        }
    }

    /**
     * @param list<string> $segments
     */
    private function pluck(mixed $value, array $segments): mixed {
        foreach ($segments as $segment) {
            if (!is_array($value)) {
                return null;
            }

            $key = ctype_digit($segment) ? (int) $segment : $segment;
            if (!array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Substitutes captured values. Returns null when a placeholder cannot be
     * filled, so the step is recorded as skipped with a reason rather than sent
     * with a literal "{{draft.id}}" and reported as a tool failure.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|null
     */
    private function resolve(array $arguments): ?array {
        $resolved = [];
        foreach ($arguments as $key => $value) {
            if (!is_string($value) || !preg_match('/^\{\{([^|}]+)(\|string)?\}\}$/', $value, $matches)) {
                $resolved[$key] = $value;

                continue;
            }

            if (!array_key_exists($matches[1], $this->captured)) {
                return null;
            }

            $captured = $this->captured[$matches[1]];
            $resolved[$key] = isset($matches[2]) ? (string) $captured : $captured;
        }

        return $resolved;
    }

    /**
     * Tools the plan never calls. Recorded in the snapshot so a gap in coverage
     * is a visible line rather than an absence nobody notices.
     *
     * @param array<string, mixed> $tools
     * @param array<string, mixed> $steps
     * @return list<string>
     */
    private function uncovered(array $tools, array $steps): array {
        $called = [];
        foreach (Plan::steps('coverage') as $step) {
            $name = is_string($step['name'] ?? null) ? $step['name'] : (string) $step['tool'];
            $status = is_array($steps[$name] ?? null) ? ($steps[$name]['status'] ?? null) : null;
            if ($status === 'ok') {
                $called[(string) $step['tool']] = true;
            }
        }

        $uncovered = array_values(array_diff(array_keys($tools), array_keys($called)));
        sort($uncovered);

        return $uncovered;
    }
}

/**
 * Snapshot comparison and the human readable summary.
 */
final class Report {
    /**
     * @param array<string, mixed> $snapshot
     */
    public static function summary(array $snapshot): string {
        $steps = is_array($snapshot['steps'] ?? null) ? $snapshot['steps'] : [];
        $counts = [];
        foreach ($steps as $step) {
            $status = is_array($step) ? (string) ($step['status'] ?? 'unknown') : 'unknown';
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        ksort($counts);
        $parts = [];
        foreach ($counts as $status => $count) {
            $parts[] = "{$status}={$count}";
        }

        $uncovered = is_array($snapshot['uncovered'] ?? null) ? $snapshot['uncovered'] : [];
        $summary = 'tools=' . ($snapshot['toolCount'] ?? 0) . ' steps: ' . implode(' ', $parts) . "\n";

        if ($uncovered !== []) {
            $summary .= 'uncovered tools (' . count($uncovered) . '): ' . implode(', ', $uncovered) . "\n";
        }

        foreach ($steps as $name => $step) {
            if (!is_array($step) || in_array($step['status'] ?? '', ['ok', 'skipped'], true)) {
                continue;
            }

            $label = array_key_exists('unexpected', $step) ? 'FAIL' : 'known';
            $detail = $step['unexpected'] ?? $step['message'] ?? $step['error'] ?? '';
            $failures = is_array($step['failures'] ?? null) ? ' ' . implode('; ', $step['failures']) : '';
            $summary .= "  {$label} {$name} [{$step['status']}] {$detail}{$failures}\n";
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function failureCount(array $snapshot): int {
        $steps = is_array($snapshot['steps'] ?? null) ? $snapshot['steps'] : [];
        $failures = 0;
        foreach ($steps as $step) {
            if (is_array($step) && array_key_exists('unexpected', $step)) {
                $failures++;
            }
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $current
     * @return list<string>
     */
    public static function diff(array $baseline, array $current): array {
        $differences = [];
        self::walk($baseline, $current, '', $differences);
        sort($differences);

        return $differences;
    }

    /**
     * @param list<string> $differences
     */
    private static function walk(mixed $baseline, mixed $current, string $path, array &$differences): void {
        if ($baseline === $current) {
            return;
        }

        if (!is_array($baseline) || !is_array($current)) {
            $differences[] = $path . ': ' . self::render($baseline) . ' -> ' . self::render($current);

            return;
        }

        foreach (array_keys($baseline + $current) as $key) {
            $child = $path === '' ? (string) $key : $path . '.' . $key;

            if (!array_key_exists($key, $current)) {
                $differences[] = $child . ': removed';

                continue;
            }

            if (!array_key_exists($key, $baseline)) {
                $differences[] = $child . ': added';

                continue;
            }

            self::walk($baseline[$key], $current[$key], $child, $differences);
        }
    }

    private static function render(mixed $value): string {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        $encoded = is_string($encoded) ? $encoded : 'unknown';

        return strlen($encoded) > 120 ? substr($encoded, 0, 117) . '...' : $encoded;
    }
}
