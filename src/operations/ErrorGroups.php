<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\operations;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use stimmt\craft\Mcp\logging\Entry;
use stimmt\craft\Mcp\logging\StackFrame;

/**
 * Logged errors inside a time window, grouped by what kind of error they are.
 *
 * WHY grouped: during an incident the question is "what keeps breaking", and a
 * list of the newest fifty entries answers it badly when forty-nine of them are
 * the same missing template.
 */
final readonly class ErrorGroups {
    /** Long enough to read the message, short enough that twenty groups fit any budget. */
    public const int MESSAGE_LENGTH = 300;

    public const int TRACE_FRAMES = 8;

    /**
     * Craft logs the request's globals as an entry of its own after the real
     * ones (MonologTarget::export), at the target's level. It is context for
     * the error before it, never an error itself.
     */
    private const string REQUEST_CONTEXT = 'Request context:';

    /**
     * @param list<Entry> $entries Newest first, as the log parser returns them
     * @param string $since Oldest timestamp to keep, in the log's own "Y-m-d H:i:s" and time zone
     */
    public function __construct(
        private array $entries,
        private string $since,
        private DateTimeZone $timeZone,
    ) {
    }

    /**
     * @return list<Entry> The errors inside the window
     */
    public function inWindow(): array {
        return array_values(array_filter(
            $this->entries,
            fn (Entry $entry): bool => $entry->timestamp >= $this->since
                && !str_starts_with($entry->headline(), self::REQUEST_CONTEXT),
        ));
    }

    /**
     * @return list<array<string, mixed>> Most frequent first, then most recent
     */
    public function groups(): array {
        $groups = [];

        foreach ($this->inWindow() as $entry) {
            $signature = ErrorSignature::of($entry);
            $key = $signature->key();
            $groups[$key] = isset($groups[$key])
                ? $this->add($groups[$key], $entry)
                : $this->open($signature, $entry);
        }

        usort(
            $groups,
            static fn (array $a, array $b): int => [$b['count'], $b['lastSeen']] <=> [$a['count'], $a['lastSeen']],
        );

        return array_map($this->present(...), $groups);
    }

    /**
     * The entries arrive newest first, so the one that opens a group is its
     * most recent occurrence, which is the example worth showing.
     *
     * @return array<string, mixed>
     */
    private function open(ErrorSignature $signature, Entry $entry): array {
        return [
            'kind' => $signature->kind,
            'message' => mb_strimwidth($signature->text, 0, self::MESSAGE_LENGTH, '…'),
            'count' => 1,
            'firstSeen' => $entry->timestamp,
            'lastSeen' => $entry->timestamp,
            'sources' => [ErrorSignature::source($entry->file) => true],
            'example' => [
                'site' => $signature->site,
                'trace' => array_map(
                    static fn (StackFrame $frame): array => $frame->toArray(),
                    array_slice($signature->frames, 0, self::TRACE_FRAMES),
                ),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private function add(array $group, Entry $entry): array {
        $group['count']++;
        $group['firstSeen'] = min($group['firstSeen'], $entry->timestamp);
        $group['lastSeen'] = max($group['lastSeen'], $entry->timestamp);
        $group['sources'][ErrorSignature::source($entry->file)] = true;

        return $group;
    }

    /**
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private function present(array $group): array {
        $group['firstSeen'] = $this->iso($group['firstSeen']);
        $group['lastSeen'] = $this->iso($group['lastSeen']);
        $group['sources'] = array_map(strval(...), array_keys($group['sources']));

        return $group;
    }

    private function iso(string $timestamp): string {
        $moment = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $timestamp, $this->timeZone);

        return $moment === false ? $timestamp : $moment->format(DateTimeInterface::ATOM);
    }
}
