<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../Fixtures/RealCraft.php';
require_once __DIR__ . '/../../../Fixtures/CustomFieldBehaviorStub.php';

use craft\elements\Asset;
use craft\models\Volume;
use craft\models\VolumeFolder;
use stimmt\craft\Mcp\elements\refs\AssetKey;

/**
 * #77: an asset in a subfolder can only be related by {volume, path,
 * filename}, so the natural key has to carry the folder path whenever the
 * asset is not in the volume root.
 */
$asset = static fn (string $path): Asset => new class (['id' => 9, 'siteId' => 1, 'filename' => 'hero.png'], $path) extends Asset {
    public function __construct(array $config, private readonly string $stubPath) {
        parent::__construct($config);
    }

    public function getVolume(): Volume {
        return new Volume(['handle' => 'images']);
    }

    public function getFolder(): VolumeFolder {
        return new VolumeFolder(['path' => $this->stubPath]);
    }

    public function getFilename(bool $withExtension = true): string {
        return 'hero.png';
    }

    public function getUrl(mixed $transform = null, ?bool $immediately = null): ?string {
        return null;
    }
};

describe('AssetKey::of()', function () use ($asset) {
    it('carries the folder path for an asset in a subfolder', function () use ($asset) {
        expect(AssetKey::of($asset('campaigns/2026/')))->toBe(['volume' => 'images', 'filename' => 'hero.png', 'path' => 'campaigns/2026/']);
    });

    it('leaves the path out for an asset in the volume root', function () use ($asset) {
        expect(AssetKey::of($asset('')))->toBe(['volume' => 'images', 'filename' => 'hero.png']);
    });
});
