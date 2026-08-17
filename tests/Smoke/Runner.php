<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Smoke;

use Throwable;

/**
 * Executes the plan against one connection and judges each result.
 *
 * Judgement is per profile, not per tool: the register of known defects can
 * name the profiles a defect belongs to, so a failure on the transport that has
 * it is expected and the same failure on the transport that does not is a
 * finding.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Runner {
    /** @var array<string, mixed> */
    private array $captured = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly Profile $profile,
        private readonly bool $includeHeavy,
    ) {
    }

    /**
     * @param array<string, mixed> $tools the advertised catalogue
     * @return array<string, mixed>
     */
    public function execute(string $runId, array $tools): array {
        $results = [];

        foreach (Plan::steps($runId) as $step) {
            $tool = (string) $step['tool'];
            $name = is_string($step['name'] ?? null) ? $step['name'] : $tool;

            $skip = $this->skipReason($step, $tools);
            if ($skip !== null) {
                $results[$name] = ['status' => 'skipped', 'reason' => $skip];

                continue;
            }

            $results[$name] = $this->judge($name, $tool, $this->call($step));
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

        $expectation = Expectations::covering($name, $this->profile->name)
            ?? Expectations::covering($tool, $this->profile->name);

        if ($expectation === null) {
            if ($result['status'] !== 'ok') {
                $result['unexpected'] = 'expected ok';
            }

            return $result;
        }

        return $this->holdToExpectation($result, $expectation);
    }

    /**
     * @param array<string, mixed> $result
     * @param array{id: string, status: string, contains?: string} $expectation
     * @return array<string, mixed>
     */
    private function holdToExpectation(array $result, array $expectation): array {
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
        $reported = (string) ($result['message'] ?? $result['error'] ?? '');
        if (is_string($contains) && !str_contains($reported, $contains)) {
            $result['unexpected'] = "{$id} changed message";
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
    private function call(array $step): array {
        $arguments = $this->resolve(is_array($step['args'] ?? null) ? $step['args'] : []);
        if ($arguments === null) {
            return ['status' => 'skipped', 'reason' => 'an earlier step did not produce the value this one needs'];
        }

        try {
            $envelope = $this->connection->callTool((string) $step['tool'], $arguments);
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

        return $this->outcome($step, is_array($envelope['result'] ?? null) ? $envelope['result'] : []);
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function outcome(array $step, array $result): array {
        $payload = $this->payload($result);

        if (is_array($payload) && is_array($step['capture'] ?? null)) {
            $this->capture($step['capture'], $payload);
        }

        if (($result['isError'] ?? false) === true) {
            $message = $this->text($result);

            return ['status' => 'tool-error', 'message' => $message, 'diagnosis' => self::diagnose($message)];
        }

        $rules = is_array($step['assert'] ?? null) ? $step['assert'] : [];
        $failures = $rules === [] ? [] : Assert::check($payload, $rules);

        if ($failures !== []) {
            return ['status' => 'assert-failed', 'failures' => $failures, 'shape' => Shape::of($payload)];
        }

        return ['status' => 'ok', 'shape' => Shape::of($payload)];
    }

    /**
     * Names a failure class when the message betrays one, so whoever hits it
     * next gets a lead instead of a stack frame.
     *
     * The web-request case is here because it is open ended: every Craft event
     * our tools fire is somewhere a third-party listener can reach for a
     * request that a console process does not have, and the error Yii produces
     * names only the missing method. One shim exists for getHeaders(); a second
     * distinct method appearing here is the signal to stop shimming and start
     * naming the plugin instead.
     */
    private static function diagnose(string $message): ?string {
        if (!str_contains($message, 'UnknownMethodException')) {
            return null;
        }

        if (!str_contains($message, 'console\\Request')) {
            return null;
        }

        return 'a listener on a Craft event assumes a web request; see support/ConsoleHeaders';
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
}
