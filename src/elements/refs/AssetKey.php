<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\elements\refs;

use Closure;
use Craft;
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

        $query = Asset::find()
            ->volume($volume)
            ->filename($filename)
            ->status(null);

        if ($path !== '') {
            return $query->folderPath(rtrim($path, '/') . '/')->ids()[0] ?? null;
        }

        // An empty path means the volume root. AssetQuery skips a falsy
        // folderPath filter entirely, so constrain to the root folder id
        // explicitly or a same-named file in any subfolder could match.
        $volumeModel = Craft::$app->getVolumes()->getVolumeByHandle($volume);
        $root = $volumeModel === null
            ? null
            : Craft::$app->getAssets()->getRootFolderByVolumeId($volumeModel->id);

        if ($root === null) {
            return null;
        }

        return $query->folderId($root->id)->ids()[0] ?? null;
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
