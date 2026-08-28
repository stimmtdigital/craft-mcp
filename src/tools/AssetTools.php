<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\elements\Asset;
use craft\models\FieldLayout;
use craft\models\Volume;
use craft\models\VolumeFolder;
use craft\services\Assets;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\support\Authorization;
use stimmt\craft\Mcp\support\HandleResolver;
use stimmt\craft\Mcp\text\Serializer;

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
        title: 'Browse assets',
        description: 'List assets from Craft CMS. Filter by volume, folder, kind (image, video, pdf, etc.), filename.',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listAssets(
        #[Schema(description: 'Volume handle; list_volumes reports the handles. Omit to list across every volume.')]
        ?string $volume = null,
        #[Schema(description: 'Numeric folder id, as list_asset_folders reports it.')]
        ?int $folderId = null,
        #[Schema(description: 'Craft asset kind, such as image, video, pdf, word, excel, audio, compressed, or text.')]
        ?string $kind = null,
        #[Schema(description: 'Matched as a substring of the filename, not as an exact name.')]
        ?string $filename = null,
        int $limit = 50,
        int $offset = 0,
        ?RequestContext $context = null,
    ): array {
        $volumeModel = HandleResolver::volume($volume);
        $folder = HandleResolver::assetFolder($folderId);

        $query = Asset::find()
            ->limit($limit)
            ->offset($offset);

        if ($volumeModel !== null) {
            $query->volumeId($volumeModel->id);
        }

        if ($folder !== null) {
            $query->folderId($folder->id);
        }

        if ($kind !== null) {
            $query->kind($kind);
        }

        if ($filename !== null) {
            $query->filename('*' . $filename . '*');
        }

        Authorization::scopeQuery($query);
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
        title: 'Read one asset',
        description: 'Get a single asset by ID with full metadata',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function getAsset(int $id, ?RequestContext $context = null): array {
        $asset = Asset::find()->id($id)->one();

        if ($asset === null) {
            throw new ToolCallException("Asset with ID {$id} not found");
        }

        Authorization::assertCanView($asset);

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
        title: 'Asset volumes',
        description: 'List all asset volumes (storage locations) in Craft CMS',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listVolumes(?RequestContext $context = null): array {
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
        title: 'Asset folders',
        description: 'List asset folders in a volume',
        annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT)]
    public function listAssetFolders(
        #[Schema(description: 'Volume handle; list_volumes reports the handles. Omit to list the root folder of every volume.')]
        ?string $volume = null,
        #[Schema(description: 'Folder id to list the children of; a folder id names its volume on its own, so volume is optional beside it. Omit for the children of the volume\'s root folder.')]
        ?int $parentId = null,
        ?RequestContext $context = null,
    ): array {
        $folders = $this->childFolders(
            Craft::$app->getAssets(),
            HandleResolver::volume($volume),
            HandleResolver::assetFolder($parentId),
        );

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
     * The children of the folder the caller named, whichever way they named
     * it. A parent stands on its own: it identifies exactly one folder in one
     * volume, so it no longer needs a volume beside it to be honoured, and
     * asking for the children of folder 1 no longer answers with folder 1.
     *
     * @return VolumeFolder[]
     */
    private function childFolders(Assets $assets, ?Volume $volume, ?VolumeFolder $parent): array {
        if ($parent === null) {
            return $this->rootChildFolders($assets, $volume);
        }

        if ($volume !== null && $parent->volumeId !== $volume->id) {
            throw new ToolCallException(
                "Asset folder {$parent->id} is not in volume '{$volume->handle}'. Omit volume, or pass the parentId of a folder inside it.",
            );
        }

        return $assets->findFolders(['parentId' => $parent->id]);
    }

    /**
     * @return VolumeFolder[]
     */
    private function rootChildFolders(Assets $assets, ?Volume $volume): array {
        if ($volume === null) {
            return $this->getAllRootFolders($assets);
        }

        $rootFolder = $assets->getRootFolderByVolumeId($volume->id);
        if ($rootFolder === null) {
            return [];
        }

        return $assets->findFolders(['parentId' => $rootFolder->id]);
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
            if ($asset->getFieldLayout() instanceof FieldLayout) {
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
