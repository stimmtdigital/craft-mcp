<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\Tests\Fixtures;

use yii\caching\ArrayCache;

/**
 * ArrayCache that records the duration every write is stored with, so a TTL can
 * be asserted without waiting for it to elapse.
 *
 * Yii writes the tag's own version entry before the value, so assertions should
 * look at the last recorded duration rather than the first.
 */
class RecordingCache extends ArrayCache {
    /** @var array<int, mixed> */
    public array $durations = [];

    public function lastDuration(): mixed {
        return end($this->durations);
    }

    protected function setValue($key, $value, $duration) {
        $this->durations[] = $duration;

        return parent::setValue($key, $value, $duration);
    }
}
