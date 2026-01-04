<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\elements\Asset;
use craft\models\VolumeFolder;
use craft\services\Assets;
use Mcp\Capability\Attribute\McpTool;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\Serializer;

/**
 * Asset-related MCP tools for Craft CMS.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
class AssetTools {
    /**
     * List assets with optional filters.
     */
    #[McpTool(
        name: 'list_assets',
        description: 'List assets from Craft CMS. Filter by volume, folder, kind (image, video, pdf, etc.), filename.',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listAssets(
        ?string $volume = null,
        ?int $folderId = null,
        ?string $kind = null,
        ?string $filename = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $query = Asset::find()
            ->limit($limit)
            ->offset($offset);

        if ($volume !== null) {
            $query->volume($volume);
        }

        if ($folderId !== null) {
            $query->folderId($folderId);
        }

        if ($kind !== null) {
            $query->kind($kind);
        }

        if ($filename !== null) {
            $query->filename('*' . $filename . '*');
        }

        $assets = $query->all();
        $results = [];

        foreach ($assets as $asset) {
            $results[] = $this->serializeAsset($asset);
        }

        return [
            'count' => count($results),
            'total' => $query->count(),
            'limit' => $limit,
            'offset' => $offset,
            'assets' => $results,
        ];
    }

    /**
     * Get a single asset by ID.
     */
    #[McpTool(
        name: 'get_asset',
        description: 'Get a single asset by ID with full metadata',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function getAsset(int $id): array {
        $asset = Asset::find()->id($id)->one();

        if ($asset === null) {
            return [
                'found' => false,
                'error' => 'Asset not found',
            ];
        }

        return [
            'found' => true,
            'asset' => $this->serializeAsset($asset, true),
        ];
    }

    /**
     * List all asset volumes.
     */
    #[McpTool(
        name: 'list_volumes',
        description: 'List all asset volumes (storage locations) in Craft CMS',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listVolumes(): array {
        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        $results = [];

        foreach ($volumes as $volume) {
            $results[] = [
                'id' => $volume->id,
                'handle' => $volume->handle,
                'name' => $volume->name,
                'type' => $volume->getFs()::class,
                'hasUrls' => $volume->getFs()->hasUrls,
                'rootUrl' => $volume->getFs()->hasUrls ? $volume->getFs()->getRootUrl() : null,
            ];
        }

        return [
            'count' => count($results),
            'volumes' => $results,
        ];
    }

    /**
     * List folders in a volume.
     */
    #[McpTool(
        name: 'list_asset_folders',
        description: 'List asset folders in a volume',
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listAssetFolders(?string $volume = null, ?int $parentId = null): array {
        $assetsService = Craft::$app->getAssets();

        $folders = $this->getAssetFolders($assetsService, $volume, $parentId);
        if ($folders === null) {
            return ['success' => false, 'error' => "Volume '{$volume}' not found"];
        }

        $results = [];
        foreach ($folders as $folder) {
            $results[] = [
                'id' => $folder->id,
                'name' => $folder->name,
                'path' => $folder->path,
                'volumeId' => $folder->volumeId,
                'parentId' => $folder->parentId,
            ];
        }

        return [
            'count' => count($results),
            'folders' => $results,
        ];
    }

    /**
     * Get asset folders based on volume and parent ID.
     *
     * @return VolumeFolder[]|null Null if volume not found
     */
    private function getAssetFolders(
        Assets $assetsService,
        ?string $volume,
        ?int $parentId,
    ): ?array {
        if ($volume === null) {
            return $this->getAllRootFolders($assetsService);
        }

        $volumeModel = Craft::$app->getVolumes()->getVolumeByHandle($volume);
        if ($volumeModel === null) {
            return null;
        }

        if ($parentId !== null) {
            return $assetsService->findFolders(['parentId' => $parentId]);
        }

        $rootFolder = $assetsService->getRootFolderByVolumeId($volumeModel->id);

        return $assetsService->findFolders(['parentId' => $rootFolder->id]);
    }

    /**
     * Get all root folders across all volumes.
     *
     * @return VolumeFolder[]
     */
    private function getAllRootFolders(Assets $assetsService): array {
        $volumes = Craft::$app->getVolumes()->getAllVolumes();

        return array_filter(
            array_map(
                fn ($vol) => $assetsService->getRootFolderByVolumeId($vol->id),
                $volumes,
            ),
        );
    }

    /**
     * Serialize an asset to array.
     */
    private function serializeAsset(Asset $asset, bool $detailed = false): array {
        $data = [
            'id' => $asset->id,
            'title' => $asset->title,
            'filename' => $asset->filename,
            'kind' => $asset->kind,
            'size' => $asset->size,
            'width' => $asset->width,
            'height' => $asset->height,
            'url' => $asset->getUrl(),
            'volumeId' => $asset->volumeId,
            'folderId' => $asset->folderId,
            'dateCreated' => $asset->dateCreated?->format('Y-m-d H:i:s'),
            'dateModified' => $asset->dateModified?->format('Y-m-d H:i:s'),
        ];

        if ($detailed) {
            $data['mimeType'] = $asset->mimeType;
            $data['extension'] = $asset->extension;
            $data['folderPath'] = $asset->folderPath;
            $data['alt'] = $asset->alt;

            // Custom fields
            $fieldValues = [];
            if ($asset->getFieldLayout()) {
                foreach ($asset->getFieldLayout()->getCustomFields() as $field) {
                    $value = $asset->getFieldValue($field->handle);
                    $fieldValues[$field->handle] = Serializer::serialize($value);
                }
            }
            $data['fields'] = $fieldValues;

            // Image-specific
            if ($asset->kind === 'image') {
                $data['focalPoint'] = $asset->focalPoint;
            }
        }

        return $data;
    }
}
