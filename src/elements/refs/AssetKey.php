<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\refs;

use Closure;
use craft\elements\Asset;

/**
 * Natural key for assets: volume handle + folder path + filename. Core has
 * no asset ref support (AssetQuery ignores the ref param), so this is the
 * one custom resolver. Lookups are injectable for Craft-free tests.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class AssetKey {
    public function __construct(
        private readonly ?Closure $lookupId = null,
        private readonly ?Closure $lookupKey = null,
    ) {
    }

    /**
     * @param array{volume?: string, path?: string, filename?: string} $key
     */
    public function idFor(array $key): ?int {
        $volume = $key['volume'] ?? null;
        $filename = $key['filename'] ?? null;
        if (!is_string($volume) || !is_string($filename) || $volume === '' || $filename === '') {
            return null;
        }

        $path = is_string($key['path'] ?? null) ? $key['path'] : '';

        if ($this->lookupId !== null) {
            return ($this->lookupId)($volume, $path, $filename);
        }

        $folderPath = $path === '' ? null : rtrim($path, '/') . '/';

        return Asset::find()
            ->volume($volume)
            ->folderPath($folderPath)
            ->filename($filename)
            ->status(null)
            ->ids()[0] ?? null;
    }

    /**
     * @return array{volume: string, path?: string, filename: string}|null
     */
    public function keyFor(int $id): ?array {
        if ($this->lookupKey !== null) {
            return ($this->lookupKey)($id);
        }

        $asset = Asset::find()->id($id)->status(null)->one();
        if (!$asset instanceof Asset) {
            return null;
        }

        $key = ['volume' => $asset->getVolume()->handle, 'filename' => (string) $asset->getFilename()];
        $path = (string) ($asset->getFolder()->path ?? '');
        if ($path !== '') {
            $key['path'] = $path;
        }

        return $key;
    }
}
