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
    /** Every tool and resource the plugin adds carries this prefix. */
    public const string OWN_PREFIX = 'stimmt_';

    public function __construct(
        private readonly bool $includeHeavy,
    ) {
    }

    /**
     * The run id seeds the name of the token an HTTP profile mints and every
     * draft name the plan writes. It is per profile rather than per run so two
     * profiles in one pass can never be confused for each other in the
     * install's tables.
     *
     * @return array<string, mixed>
     */
    public function run(Profile $profile): array {
        $runId = 'r' . substr(sha1($profile->name . getmypid() . microtime()), 0, 8);
        $credentials = $profile->isHttp() ? Credentials::for($profile->level, $profile->scopes, $runId) : null;
        $client = $credentials === null
            ? new StdioClient(Install::command('php craft mcp/serve --user=' . escapeshellarg(Install::admin())))
            : new HttpClient($credentials->endpoint, $credentials->token);

        try {
            $client->start();
            $initialize = $client->initialize();
            $listed = $client->listAll('tools/list', 'tools');
            $resources = $client->listAll('resources/list', 'resources');
            $runner = new Runner($client, $profile, $this->includeHeavy);
            $steps = $runner->execute($runId, self::names([...$listed, ...$resources]));
            $scope = Boundary::of($client, $profile, $this->catalogue($listed), $runner->captured());
        } finally {
            $diagnostics = $client->diagnostics();
            $client->stop();
            $credentials?->release();
        }

        return $this->snapshot($initialize, $listed, $resources, $steps, $scope, $diagnostics);
    }

    /**
     * @param list<array<string, mixed>> $listed
     * @return array<string, true>
     */
    private static function names(array $listed): array {
        $names = [];
        foreach ($listed as $tool) {
            if (is_string($tool['name'] ?? null)) {
                $names[$tool['name']] = true;
            }
        }

        return $names;
    }

    /**
     * The plugin's own tools, recorded verbatim rather than shaped. A tool
     * name, description, annotation or schema changing by accident is exactly
     * the regression we cannot afford, and an annotation is now also what
     * decides the scope a tool needs.
     *
     * Craft's own tools are counted, not recorded: their wording is Craft's
     * business and would drift with every Craft release, but the count still
     * moves if the plugin's Gate ever starts hiding tools that are not its own.
     *
     * @param list<array<string, mixed>> $listed
     * @return array<string, mixed>
     */
    private function catalogue(array $listed): array {
        $catalogue = [];
        foreach ($listed as $tool) {
            $name = $tool['name'] ?? null;
            if (!is_string($name) || !str_starts_with($name, self::OWN_PREFIX)) {
                continue;
            }

            $catalogue[$name] = [
                'title' => $tool['title'] ?? null,
                'description' => $tool['description'] ?? null,
                'annotations' => $tool['annotations'] ?? null,
                'inputSchema' => $tool['inputSchema'] ?? null,
            ];
        }

        ksort($catalogue);

        return $catalogue;
    }

    /**
     * @param list<array<string, mixed>> $resources
     * @return array<string, mixed>
     */
    private function ownResources(array $resources): array {
        $own = [];
        foreach ($resources as $resource) {
            $name = $resource['name'] ?? null;
            if (is_string($name) && str_starts_with($name, self::OWN_PREFIX)) {
                $own[$name] = [
                    'uri' => $resource['uri'] ?? null,
                    'description' => $resource['description'] ?? null,
                    'mimeType' => $resource['mimeType'] ?? null,
                ];
            }
        }

        ksort($own);

        return $own;
    }

    /**
     * The scope block is present only on a connection that carries a scope.
     * stdio carries none at all, and recording a null there would claim a
     * boundary that does not exist on that transport.
     *
     * @param array<string, mixed> $initialize
     * @param list<array<string, mixed>> $listed
     * @param list<array<string, mixed>> $resources
     * @param array<string, mixed> $steps
     * @param array<string, mixed>|null $scope
     * @return array<string, mixed>
     */
    private function snapshot(array $initialize, array $listed, array $resources, array $steps, ?array $scope, string $diagnostics): array {
        $tools = $this->catalogue($listed);

        $snapshot = [
            'protocolVersion' => $initialize['protocolVersion'] ?? null,
            'capabilities' => $initialize['capabilities'] ?? [],
            'toolCount' => count($tools),
            'craftToolCount' => count($listed) - count($tools),
            'tools' => $tools,
            'resources' => $this->ownResources($resources),
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
     * The plugin's tools the plan never got an ok answer from. Recorded in the
     * snapshot so a gap in coverage is a visible line rather than an absence
     * nobody notices.
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
            if (in_array($status, ['ok', 'refused'], true)) {
                $called[(string) $step['tool']] = true;
            }
        }

        $uncovered = array_values(array_diff(array_keys($tools), array_keys($called)));
        sort($uncovered);

        return $uncovered;
    }
}
