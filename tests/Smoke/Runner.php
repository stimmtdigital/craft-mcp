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

    /**
     * The whole text of the last answer, unshortened, for a refusal check: the
     * name a refusal must carry can sit past the excerpt kept as `message`.
     */
    private string $lastText = '';

    public function __construct(
        private readonly Client $client,
        private readonly Profile $profile,
        private readonly bool $includeHeavy,
    ) {
    }

    /**
     * @param array<string, true> $tools every tool and resource name the
     *                                   connection advertises, Craft's included
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

            $refusal = is_string($step['refusal'] ?? null) ? $step['refusal'] : null;
            $results[$name] = $refusal === null
                ? $this->judge($name, $tool, $this->call($step))
                : $this->holdToRefusal($name, $this->call($step), $refusal);
        }

        return $results;
    }

    /**
     * What the plan captured on the way, for the boundary probes that need a
     * real id to make a call that would succeed if it were allowed.
     *
     * @return array<string, mixed>
     */
    public function captured(): array {
        return $this->captured;
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, mixed> $tools
     */
    private function skipReason(array $step, array $tools): ?string {
        if (is_string($step['skip'] ?? null) && !($this->includeHeavy && ($step['tag'] ?? null) === Plan::TAG_HEAVY)) {
            return $step['skip'];
        }

        $profiles = is_array($step['profiles'] ?? null) ? $step['profiles'] : null;
        if ($profiles !== null && !in_array($this->profile->name, $profiles, true)) {
            return 'runs on ' . implode(', ', $profiles) . ' only';
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

        $expectation = Expectation::covering($name, $this->profile->name)
            ?? Expectation::covering($tool, $this->profile->name);

        if ($expectation === null) {
            if ($result['status'] !== 'ok') {
                $result['unexpected'] = 'expected ok';
            }

            return $result;
        }

        return $this->holdToExpectation($result, $expectation);
    }

    /**
     * A step whose correct outcome is a refusal: a write the server must turn
     * down, such as publishing a draft that fails validation. It has to come
     * back as a tool error naming $contains; succeeding is the failure here.
     *
     * A refusal that arrives in a known wrong form (see Expectation) is held
     * to that form instead, and still has to name $contains, so the register
     * pins the defect without forgiving a refusal that stopped saying why.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function holdToRefusal(string $name, array $result, string $contains): array {
        if ($result['status'] === 'skipped') {
            return $result;
        }

        $expectation = Expectation::covering($name, $this->profile->name);
        $named = str_contains($this->lastText, $contains);

        if ($result['status'] === 'tool-error' && $named) {
            return $expectation === null
                ? ['status' => 'refused', 'refusal' => $contains]
                : ['status' => 'refused', 'refusal' => $contains, 'expected' => $expectation['id'], 'unexpected' => "{$expectation['id']} no longer reproduces: delete it from Expectation"];
        }

        if ($expectation !== null && $result['status'] === $expectation['status'] && $named
            && str_contains($this->lastText, (string) ($expectation['contains'] ?? ''))) {
            // Held to the known form. The wording carries ids that change
            // every run, so the fact recorded is the form, not the message.
            return ['status' => $result['status'], 'refusal' => $contains, 'expected' => $expectation['id']];
        }

        $result['refusal'] = $contains;
        $result['unexpected'] = $named
            ? "expected a refusal naming \"{$contains}\", got {$result['status']}"
            : "expected a refusal naming \"{$contains}\", and the answer does not name it";

        return $result;
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
            $result['unexpected'] = "{$id} no longer reproduces: delete it from Expectation";

            return $result;
        }

        if ($result['status'] !== $expectation['status']) {
            $result['unexpected'] = "{$id} changed symptom, now {$result['status']}";

            return $result;
        }

        $contains = $expectation['contains'] ?? null;
        if (is_string($contains) && !str_contains($this->lastText, $contains)) {
            $result['unexpected'] = "{$id} changed message";

            return $result;
        }

        // Held. The message stays out of the snapshot: it carries ids that
        // change every run, and the expectation already pins its wording.
        unset($result['message'], $result['error']);

        return $result;
    }

    /**
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
    private function call(array $step): array {
        $this->lastText = '';
        $arguments = $this->resolve(is_array($step['args'] ?? null) ? $step['args'] : []);
        if ($arguments === null) {
            return ['status' => 'skipped', 'reason' => 'an earlier step did not produce the value this one needs'];
        }

        $uri = is_string($step['uri'] ?? null) ? $step['uri'] : null;

        try {
            $envelope = $uri === null
                ? $this->client->callTool((string) $step['tool'], $arguments)
                : $this->client->send('resources/read', ['uri' => $uri]);
        } catch (Throwable $exception) {
            return ['status' => 'crashed', 'error' => Redact::text($exception->getMessage())];
        }

        if (isset($envelope['error']) && is_array($envelope['error'])) {
            return [
                'status' => 'protocol-error',
                'code' => $envelope['error']['code'] ?? null,
                'message' => Redact::text((string) ($envelope['error']['message'] ?? '')),
            ];
        }

        $result = is_array($envelope['result'] ?? null) ? $envelope['result'] : [];

        return $uri === null ? $this->outcome($step, $result) : $this->checked($step, self::resource($result));
    }

    /**
     * A resource answers with contents rather than tool content, and its text
     * is markdown rather than JSON, so it is kept as text for the assertions
     * and reduced to its shape like any payload.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private static function resource(array $result): array {
        $contents = is_array($result['contents'] ?? null) ? $result['contents'] : [];
        $first = is_array($contents[0] ?? null) ? $contents[0] : [];

        return [
            'contents' => count($contents),
            'uri' => $first['uri'] ?? null,
            'mimeType' => $first['mimeType'] ?? null,
            'text' => is_string($first['text'] ?? null) ? $first['text'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function outcome(array $step, array $result): array {
        $this->lastText = self::fullText($result);
        $payload = ($step['asText'] ?? false) === true ? ['text' => $this->lastText] : $this->payload($result);

        if (is_array($payload) && is_array($step['capture'] ?? null)) {
            $this->capture($step['capture'], $payload);
        }

        if (($result['isError'] ?? false) === true) {
            $message = $this->text($result);

            return ['status' => 'tool-error', 'message' => $message, 'diagnosis' => self::diagnose($message)];
        }

        // A payload that states its own failure without the isError flag. A
        // client reads the outcome from the flag, so to every client this
        // call succeeded; it is its own status rather than an assertion so
        // the register can pin it per step.
        if (is_array($payload) && ($payload['success'] ?? null) === false) {
            return ['status' => 'unflagged-failure', 'message' => $this->text($result)];
        }

        return $this->checked($step, $payload);
    }

    /**
     * The value checks, with captured values substituted into the expectations
     * the same way they are into arguments.
     *
     * WHY an expectation may name a capture: a rule is only ever as strong as
     * what it compares against. Proving that a `site` argument reached the
     * query means checking the answer carries the handle that was asked for,
     * and `notEmpty` holds just as well on an install where the argument was
     * accepted and quietly dropped.
     *
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
    private function checked(array $step, mixed $payload): array {
        $rules = is_array($step['assert'] ?? null) ? $step['assert'] : [];
        $resolved = $rules === [] ? [] : $this->resolve($rules);

        if ($resolved === null) {
            return ['status' => 'skipped', 'reason' => 'an earlier step did not produce the value an assertion needs'];
        }

        $failures = $resolved === [] ? [] : Assert::check($payload, $resolved, ($step['secret'] ?? false) === true);
        $shape = Shape::of(self::withoutState($payload, is_array($step['state'] ?? null) ? $step['state'] : []));

        return $failures === []
            ? ['status' => 'ok', 'shape' => $shape]
            : ['status' => 'assert-failed', 'failures' => $failures, 'shape' => $shape];
    }

    /**
     * Booleans a step names under `state` describe the install, not the
     * server: whether the queue holds more than a page depends on how many
     * jobs earlier runs left behind on an install with no worker running. They
     * are recorded as their type, so the baseline still pins that the key is
     * there and is a boolean, and a second run on the same install agrees.
     *
     * @param string[] $keys top-level keys of the payload
     */
    private static function withoutState(mixed $payload, array $keys): mixed {
        if (!is_array($payload)) {
            return $payload;
        }

        foreach ($keys as $key) {
            if (is_bool($payload[$key] ?? null)) {
                $payload[$key] = '<bool>';
            }
        }

        return $payload;
    }

    /**
     * Names a failure class when the message betrays one, so whoever hits it
     * next gets a lead instead of a stack frame.
     *
     * The web-request case is here because it is open ended: every Craft event
     * our tools fire is somewhere a third-party listener can reach for a
     * request that a console process does not have, and the error Yii produces
     * names only the missing method.
     */
    private static function diagnose(string $message): ?string {
        if (!str_contains($message, 'UnknownMethodException')) {
            return null;
        }

        if (!str_contains($message, 'console\\Request')) {
            return null;
        }

        return 'a listener on a Craft event assumes a web request, and stdio (craft mcp/serve) is a console request';
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function fullText(array $result): string {
        $content = is_array($result['content'] ?? null) ? $result['content'] : [];
        $first = is_array($content[0] ?? null) ? $content[0] : [];

        return is_string($first['text'] ?? null) ? $first['text'] : '';
    }

    /**
     * @param array<string, mixed> $result
     */
    private function text(array $result): string {
        $text = Redact::text(self::fullText($result));

        return strlen($text) > 300 ? substr($text, 0, 297) . '...' : $text;
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
