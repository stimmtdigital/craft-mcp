<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Smoke;

/**
 * Runs one profile end to end and returns its snapshot: open the connection the
 * profile asks for, hand it the credentials it needs, execute the plan, probe
 * the scope boundary, and record what came back.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class Harness {
    public function __construct(
        private readonly string $serverCommand,
        private readonly bool $includeHeavy,
    ) {
    }

    /**
     * The run id seeds every title and slug the write lifecycle creates, and
     * the name of the token an HTTP profile mints. It is per profile rather
     * than per run so two profiles writing the same content in one pass cannot
     * collide over a slug the previous one has only just deleted.
     *
     * @return array<string, mixed>
     */
    public function run(Profile $profile): array {
        $runId = 'r' . substr(sha1($profile->name . getmypid() . microtime()), 0, 8);
        $credentials = $profile->isHttp() ? Credentials::for((string) $profile->scope, $runId) : null;
        $connection = $credentials === null
            ? new StdioClient($this->serverCommand)
            : new HttpClient($credentials->endpoint, $credentials->token);

        $connection->start();

        try {
            $initialize = $connection->initialize();
            $tools = $this->catalogue($connection);
            $steps = (new Runner($connection, $profile, $this->includeHeavy))->execute($runId, $tools);
            $scope = Boundary::of($connection, $profile, $tools);
        } finally {
            $diagnostics = $connection->diagnostics();
            $connection->stop();
            $credentials?->release();
        }

        return $this->snapshot($initialize, $tools, $steps, $scope, $diagnostics);
    }

    /**
     * The catalogue is recorded verbatim, not shaped. A tool name, description
     * or schema changing by accident is exactly the regression we cannot afford,
     * and unlike a payload none of it is volatile.
     *
     * @return array<string, mixed>
     */
    private function catalogue(Connection $connection): array {
        $result = $connection->request('tools/list', []);
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
     * The scope block is present only on a connection that carries a scope.
     * stdio carries none at all, and recording a null there would claim a
     * boundary that does not exist on that transport.
     *
     * @param array<string, mixed> $initialize
     * @param array<string, mixed> $tools
     * @param array<string, mixed> $steps
     * @param array<string, mixed>|null $scope
     * @return array<string, mixed>
     */
    private function snapshot(array $initialize, array $tools, array $steps, ?array $scope, string $diagnostics): array {
        $snapshot = [
            'protocolVersion' => $initialize['protocolVersion'] ?? null,
            'capabilities' => $initialize['capabilities'] ?? [],
            'toolCount' => count($tools),
            'tools' => $tools,
            'uncovered' => $this->uncovered($tools, $steps),
            'steps' => $steps,
            'stderr' => trim($diagnostics) === '' ? null : '<present>',
        ];

        if ($scope !== null) {
            $snapshot['scope'] = $scope;
        }

        return $snapshot;
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
