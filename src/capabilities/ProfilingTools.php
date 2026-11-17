<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\capabilities;

use Craft;
use craft\mcp\attributes\RequiresAdmin;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use stimmt\craft\Mcp\insight\DebugIndex;
use stimmt\craft\Mcp\insight\DebugStorage;
use stimmt\craft\Mcp\insight\QueryDuplicates;
use stimmt\craft\Mcp\support\Budget;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\Window;

/**
 * The slowest recent requests, from what the Yii debug module recorded.
 */
final class ProfilingTools {
    /** Each request shown reads its own data file for the duplicate queries, so the page is kept small. */
    public const int MAX_LIMIT = 50;

    /** Where yii\debug\Module keeps its files unless config/debug.php moves them. */
    private const string DEFAULT_DATA_PATH = '@runtime/debug';

    #[McpTool(
        name: 'stimmt_requests_slow',
        title: 'Slow requests',
        description: 'The slowest recent requests this server recorded, slowest first: URL path (no query string), method, status, duration, peak memory, query count and the statements repeated most within each request, with literals replaced by ?. Reads the Yii debug module\'s data, which Craft only records while the debug toolbar is on (an admin\'s "Show the debug toolbar" preference, or Dev Mode with an X-Debug: enable header), and only on this server, so it covers the requests made with it on. Request parameters, headers and cookies are never returned.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[RequiresAdmin]
    public function slowRequests(
        #[Schema(description: 'How many requests to return, 1 to ' . self::MAX_LIMIT . '.', minimum: Window::MIN_LIMIT, maximum: self::MAX_LIMIT)]
        int $limit = 10,
        #[Schema(description: 'Leave out requests faster than this many milliseconds. Requests whose duration was not recorded are left out too when this is set.', minimum: 0)]
        ?float $minMs = null,
    ): array {
        Window::assert($limit);
        $this->assertLimit($limit);
        $storage = $this->storage();
        $manifest = $this->manifest($storage);
        $summaries = DebugIndex::summaries($manifest, $minMs);

        $requests = array_map(
            static fn (array $summary): array => self::request($storage, $summary),
            array_slice($summaries, 0, $limit),
        );

        return Budget::assert(Response::capped('requests', $requests, $limit, count($summaries), [
            'recorded' => count($manifest),
        ]));
    }

    private function assertLimit(int $limit): void {
        if ($limit > self::MAX_LIMIT) {
            throw new ToolCallException(
                'limit may not exceed ' . self::MAX_LIMIT . ", got {$limit}. Use minMs to narrow instead.",
            );
        }
    }

    /**
     * Honors a dataPath set in config/debug.php, which Craft applies to the
     * module. A Craft filesystem (`fs`) there means the files are not on this
     * disk at all, so that is refused by name rather than reported as empty.
     */
    private function storage(): DebugStorage {
        $config = Craft::$app->getConfig()->getConfigFromFile('debug');

        if (($config['fs'] ?? null) !== null) {
            throw new ToolCallException(
                'config/debug.php stores debug data on a Craft filesystem ("fs"), which this tool does not read.'
                . ' Remove the fs setting to keep the data in ' . self::DEFAULT_DATA_PATH . '.',
            );
        }

        $path = is_string($config['dataPath'] ?? null) ? $config['dataPath'] : self::DEFAULT_DATA_PATH;

        return new DebugStorage((string) Craft::getAlias($path));
    }

    /**
     * @return array<mixed>
     */
    private function manifest(DebugStorage $storage): array {
        if (!$storage->hasIndex()) {
            throw new ToolCallException(
                'No debug data at ' . $storage->directory() . ': the Yii debug module has not recorded any request.'
                . ' Turn on "Show the debug toolbar" in an admin\'s account preferences (or use Dev Mode with an'
                . ' X-Debug: enable request header), load the pages to measure, then ask again. The data is'
                . ' written by and kept on this server only.',
            );
        }

        return $storage->manifest() ?? throw new ToolCallException(
            'The debug index at ' . $storage->directory() . '/index.data could not be read. It may be mid-write;'
            . ' ask again, or clear the directory to let the module start a new one.',
        );
    }

    /**
     * duplicateQueries is null, not empty, when the request's data file is gone
     * (the module prunes old ones) or holds no db panel: "not read" and "no
     * repeats" must not look alike.
     *
     * @param array{tag: string, url: string, method: string|null, statusCode: int|null, durationMs: float|null, memoryMb: float|null, queryCount: int|null, time: string|null} $summary
     * @return array<string, mixed>
     */
    private static function request(DebugStorage $storage, array $summary): array {
        $db = $storage->panel($summary['tag'], 'db');
        $messages = is_array($db['messages'] ?? null) ? $db['messages'] : null;

        return [
            'tag' => $summary['tag'],
            'url' => $summary['url'],
            'method' => $summary['method'],
            'statusCode' => $summary['statusCode'],
            'durationMs' => $summary['durationMs'],
            'memoryMb' => $summary['memoryMb'],
            'queryCount' => $summary['queryCount'],
            'duplicateQueries' => $messages === null ? null : QueryDuplicates::group($messages),
            'time' => $summary['time'],
        ];
    }
}
