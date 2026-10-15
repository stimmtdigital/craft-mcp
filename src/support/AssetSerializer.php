<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\support;

use craft\elements\Asset;
use craft\models\FieldLayout;
use stimmt\craft\Mcp\text\Serializer;

/**
 * An asset as the asset tools report it. Shared so that what upload_asset and
 * update_asset hand back is exactly what get_asset would read.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class AssetSerializer {
    public static function serialize(Asset $asset, bool $detailed = false): array {
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
