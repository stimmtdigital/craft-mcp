<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\tools;

use Craft;
use craft\elements\Asset;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\FileHelper;
use craft\models\Volume;
use craft\models\VolumeFolder;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server\RequestContext;
use stimmt\craft\Mcp\attributes\McpToolMeta;
use stimmt\craft\Mcp\attributes\RequiresEdition;
use stimmt\craft\Mcp\elements\Context;
use stimmt\craft\Mcp\elements\refs\AssetKey;
use stimmt\craft\Mcp\elements\Warning;
use stimmt\craft\Mcp\elements\Writer;
use stimmt\craft\Mcp\enums\Edition;
use stimmt\craft\Mcp\enums\ToolCategory;
use stimmt\craft\Mcp\http\fetch\CurlTransport;
use stimmt\craft\Mcp\http\fetch\DnsResolver;
use stimmt\craft\Mcp\http\fetch\SafeFetch;
use stimmt\craft\Mcp\Mcp;
use stimmt\craft\Mcp\support\AssetIntake;
use stimmt\craft\Mcp\support\AssetSerializer;
use stimmt\craft\Mcp\support\Authorization;
use stimmt\craft\Mcp\support\ElementModule;
use stimmt\craft\Mcp\support\FolderPath;
use stimmt\craft\Mcp\support\HandleResolver;
use stimmt\craft\Mcp\support\Response;
use stimmt\craft\Mcp\support\WriteParams;

/**
 * Asset writes (#71): upload a file, edit its metadata, rename or move it,
 * trash it. Assets have no drafts, so every write is live.
 *
 * Content-scope tokens are held to Craft's own asset permissions through
 * Authorization (saveAssets, deleteAssets, createFolders and their peer
 * variants, per volume), and Craft's allowedFileExtensions applies on save as
 * it does in the control panel.
 *
 * @author stimmt.digital
 */
class AssetWriteTools {
    private const string ON_CONFLICT_RENAME = 'rename';

    private const string ON_CONFLICT_FAIL = 'fail';

    private readonly Writer $writer;

    private readonly AssetKey $keys;

    public function __construct(?Writer $writer = null, ?AssetKey $keys = null, private readonly ?AssetIntake $intake = null) {
        $this->writer = $writer ?? ElementModule::writer();
        $this->keys = $keys ?? new AssetKey();
    }

    #[McpTool(
        name: 'upload_asset',
        title: 'Upload an asset',
        description: 'Upload a file into a volume. Pass exactly one of url (a file on the public internet, fetched over https by the server: no redirects, no private or internal addresses) or data (base64, for small files such as an SVG). Both are capped by the assetUploadMaxBytes setting. Returns the asset as get_asset reads it, plus its natural key {volume, path, filename} for relation fields.',
        annotations: new ToolAnnotations(destructiveHint: false, openWorldHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    #[RequiresEdition(Edition::Pro)]
    public function uploadAsset(
        #[Schema(description: 'Volume handle to upload into; list_volumes reports the handles.')]
        string $volume,
        #[Schema(description: 'Filename including its extension. The extension must be one Craft\'s allowedFileExtensions permits.')]
        string $filename,
        #[Schema(description: 'https URL of the file. The server fetches it once, does not follow redirects, and refuses hosts that resolve to private, loopback or link-local addresses.')]
        ?string $url = null,
        #[Schema(description: 'The file as base64, or as a data: URI. For small files; use url for anything large.')]
        ?string $data = null,
        #[Schema(description: 'Folder path within the volume, such as "blog/2026". Omit for the volume root. Missing folders are created when the token\'s user may create folders.')]
        ?string $folder = null,
        #[Schema(description: 'Asset title. Omit to let Craft derive one from the filename.')]
        ?string $title = null,
        #[Schema(description: 'Alternative text for the image.')]
        ?string $alt = null,
        #[Schema(description: 'Custom field values as a JSON-encoded STRING (not a nested object), in the payload format, for the fields on the volume\'s field layout.')]
        ?string $fields = null,
        #[Schema(description: 'When a file with this name is already in the folder: "rename" (default) gives the new one a unique name, "fail" refuses the upload.', enum: [self::ON_CONFLICT_RENAME, self::ON_CONFLICT_FAIL])]
        string $onConflict = self::ON_CONFLICT_RENAME,
        ?RequestContext $context = null,
    ): array {
        $volumeModel = $this->volume($volume);
        if (!in_array($onConflict, [self::ON_CONFLICT_RENAME, self::ON_CONFLICT_FAIL], true)) {
            throw new ToolCallException("Unknown onConflict '{$onConflict}'; use rename or fail.");
        }

        // Before anything is fetched: an unsaved asset in the target volume,
        // uploaded by this user, is exactly what Craft's canSave inspects.
        Authorization::assertCanSave(new Asset(['volumeId' => $volumeModel->id, 'uploaderId' => Craft::$app->getUser()->getId()]));

        $bytes = ($this->intake ?? $this->defaultIntake())->bytes($url, $data);
        $target = $this->folder($volumeModel, $folder);
        $name = AssetsHelper::prepareAssetName($filename);
        $tempPath = AssetsHelper::tempFilePath(pathinfo($name, PATHINFO_EXTENSION) ?: 'tmp');

        try {
            if (file_put_contents($tempPath, $bytes) === false) {
                throw new ToolCallException('The file could not be written to Craft\'s temp folder.');
            }

            $asset = new Asset();
            $asset->tempFilePath = $tempPath;
            $asset->setFilename($name);
            $asset->setMimeType(FileHelper::getMimeType($tempPath, checkExtension: false));
            $asset->newFolderId = $target->id;
            $asset->setVolumeId((int) $volumeModel->id);
            $asset->uploaderId = Craft::$app->getUser()->getId();
            $asset->avoidFilenameConflicts = $onConflict === self::ON_CONFLICT_RENAME;
            $asset->setScenario(Asset::SCENARIO_CREATE);

            return $this->save($asset, 'created', $title, $alt, $fields);
        } finally {
            // Craft streams the temp file into the volume and leaves it behind.
            if (is_file($tempPath)) {
                FileHelper::unlink($tempPath);
            }
        }
    }

    #[McpTool(
        name: 'update_asset',
        title: 'Update an asset',
        description: 'Update an asset\'s title, alt text or custom fields, rename its file, or move it to another folder in the same volume. Address it by id, or by its natural key (volume, folder, filename). Only supplied values change. The file\'s contents cannot be replaced.',
        annotations: new ToolAnnotations(destructiveHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    #[RequiresEdition(Edition::Pro)]
    public function updateAsset(
        #[Schema(description: 'Asset id. Or omit it and address the asset by volume, folder and filename.')]
        ?int $id = null,
        #[Schema(description: 'Volume handle, when addressing the asset by its natural key.')]
        ?string $volume = null,
        #[Schema(description: 'Folder path the asset is in, when addressing it by its natural key. Omit for the volume root.')]
        ?string $folder = null,
        #[Schema(description: 'Current filename, when addressing the asset by its natural key.')]
        ?string $filename = null,
        ?string $title = null,
        #[Schema(description: 'Alternative text for the image.')]
        ?string $alt = null,
        #[Schema(description: 'Custom field values as a JSON-encoded STRING (not a nested object), in the payload format. Only the handles present are written.')]
        ?string $fields = null,
        #[Schema(description: 'Rename the file. Keep the extension; it must be one Craft allows.')]
        ?string $newFilename = null,
        #[Schema(description: 'Move the file to this folder path in the same volume ("" for the root). Missing folders are created when the token\'s user may create folders.')]
        ?string $newFolder = null,
        ?RequestContext $context = null,
    ): array {
        $asset = $this->find($id, $volume, $folder, $filename);
        Authorization::assertCanSave($asset);

        if ($newFolder !== null) {
            // A move takes the file out of its folder, which the control
            // panel only lets a user who may delete it do.
            Authorization::assertCanDelete($asset);
            $asset->newFolderId = $this->folder($asset->getVolume(), $newFolder)->id;
            $asset->setScenario(Asset::SCENARIO_MOVE);
        }

        if ($newFilename !== null) {
            $asset->newFilename = AssetsHelper::prepareAssetName($newFilename);
            $asset->setScenario(Asset::SCENARIO_FILEOPS);
        }

        return $this->save($asset, 'updated', $title, $alt, $fields);
    }

    #[McpTool(
        name: 'delete_asset',
        title: 'Move an asset to the trash',
        description: 'Soft-delete an asset (moves to trash, restorable in the control panel). Address it by id, or by its natural key (volume, folder, filename).',
        annotations: new ToolAnnotations(destructiveHint: true),
    )]
    #[McpToolMeta(category: ToolCategory::CONTENT, dangerous: true)]
    #[RequiresEdition(Edition::Pro)]
    public function deleteAsset(
        #[Schema(description: 'Asset id. Or omit it and address the asset by volume, folder and filename.')]
        ?int $id = null,
        ?string $volume = null,
        ?string $folder = null,
        ?string $filename = null,
        ?RequestContext $context = null,
    ): array {
        $asset = $this->find($id, $volume, $folder, $filename);
        Authorization::assertCanDelete($asset);

        if (!Craft::$app->getElements()->deleteElement($asset)) {
            throw new ToolCallException('Failed to delete asset');
        }

        return Response::success(['deleted' => $asset->id, 'restorable' => true]);
    }

    private function save(Asset $asset, string $action, ?string $title, ?string $alt, ?string $fields): array {
        if ($title !== null) {
            $asset->title = $title;
        }

        if ($alt !== null) {
            $asset->alt = $alt;
        }

        $context = new Context();
        $payload = WriteParams::fieldsPayload($fields);
        if ($payload !== []) {
            $asset->setFieldValues($this->writer->prepare($asset->getFieldLayout(), $payload, $context));
        }

        $warnings = array_map(static fn (Warning $w): array => $w->toArray(), $context->warnings());
        if (!Craft::$app->getElements()->saveElement($asset)) {
            return Response::failure(['action' => $action, 'errors' => $asset->getErrors(), 'warnings' => $warnings]);
        }

        // Read back rather than reported from the object just saved: a
        // rename or move settles its final filename and folder on save.
        $saved = Asset::find()->id($asset->id)->status(null)->one() ?? $asset;

        return Response::success([
            'action' => $action,
            'asset' => AssetSerializer::serialize($saved, true),
            'key' => $this->keys->keyFor((int) $saved->id),
            'cpEditUrl' => $saved->getCpEditUrl(),
            'warnings' => $warnings,
        ]);
    }

    private function find(?int $id, ?string $volume, ?string $folder, ?string $filename): Asset {
        if ($id === null) {
            if ($volume === null || $filename === null) {
                throw new ToolCallException('Pass the asset id, or volume and filename (with folder when it is not in the volume root).');
            }

            $this->volume($volume);
            $id = $this->keys->idFor(['volume' => $volume, 'path' => FolderPath::normalize($folder), 'filename' => $filename]);
        }

        $asset = $id === null ? null : Asset::find()->id($id)->status(null)->one();

        return $asset ?? throw new ToolCallException('Asset not found. Use list_assets to find its id.');
    }

    private function volume(string $handle): Volume {
        return HandleResolver::volume($handle) ?? throw new ToolCallException("Volume '{$handle}' not found.");
    }

    /**
     * The folder at this path, created when missing and the user may create
     * folders. Created on the volume itself too, as the control panel does.
     */
    private function folder(Volume $volume, ?string $path): VolumeFolder {
        $assets = Craft::$app->getAssets();
        $normalized = FolderPath::normalize($path);

        $existing = $normalized === ''
            ? $assets->getRootFolderByVolumeId((int) $volume->id)
            : $assets->findFolder(['volumeId' => $volume->id, 'path' => $normalized]);

        if ($existing !== null) {
            return $existing;
        }

        Authorization::assertCan("createFolders:{$volume->uid}", "create folders in volume '{$volume->handle}'");

        return $assets->ensureFolderByFullPathAndVolume($normalized, $volume, false);
    }

    private function defaultIntake(): AssetIntake {
        $maxBytes = Mcp::settings()->assetUploadMaxBytes;

        return new AssetIntake(new SafeFetch((new DnsResolver())(...), (new CurlTransport())(...), $maxBytes), $maxBytes);
    }
}
