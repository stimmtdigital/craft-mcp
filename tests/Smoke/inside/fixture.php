<?php

declare(strict_types=1);

/**
 * Installs the content model the smoke plan is written against. Never run
 * directly: tests/Smoke/fixture.php pipes this into `php` in the Craft root and
 * passes the handles from Plan as one JSON argument, so Plan stays the one
 * place they are named.
 *
 * Creates one channel section with URLs, one entry type carrying a Matrix field
 * (one block type, no fields), an Entries field relating back to the section,
 * and a required text field, plus one live entry, `smoke-fixture`, for the
 * steps to find. Everything is named for the harness, so it cannot collide with
 * a site's real content.
 *
 * Idempotent: anything that already exists is reused. It writes project
 * config, so on a client site keep the resulting config/project changes out of
 * that site's commits.
 */

use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Entries as EntriesField;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;

$root = getcwd();
if ($root === false || !is_file($root . '/bootstrap.php')) {
    fwrite(STDERR, "Run in the Craft root (no bootstrap.php in the working directory).\n");
    exit(1);
}

$handles = json_decode((string) ($argv[1] ?? ''), true);
if (!is_array($handles)) {
    fwrite(STDERR, "Pass the fixture handles as one JSON argument.\n");
    exit(1);
}

require $root . '/bootstrap.php';
require $root . '/vendor/craftcms/cms/bootstrap/console.php';

$saved = static function (bool $ok, object $model, string $what): void {
    if (!$ok) {
        $errors = method_exists($model, 'getErrors') ? json_encode($model->getErrors()) : '';

        throw new RuntimeException("Could not save {$what}: {$errors}");
    }
};

$layout = static function (array $fields, bool $withTitle, array $required = []): FieldLayout {
    $layout = new FieldLayout(['type' => Entry::class]);
    $elements = $withTitle ? [new EntryTitleField()] : [];
    foreach ($fields as $field) {
        $elements[] = new CustomField($field, ['required' => in_array($field->handle, $required, true)]);
    }
    $layout->setTabs([new FieldLayoutTab(['name' => 'Content', 'layout' => $layout, 'elements' => $elements])]);

    return $layout;
};

$entries = Craft::$app->getEntries();
$fields = Craft::$app->getFields();
$report = [];

try {
    // The block type has no fields and no title of its own:
    // stimmt_nested_entries_create is called with nothing but owner, field and type.
    $blockType = $entries->getEntryTypeByHandle($handles['BLOCK_TYPE']);
    if ($blockType === null) {
        $blockType = new EntryType([
            'name' => 'MCP smoke block',
            'handle' => $handles['BLOCK_TYPE'],
            'hasTitleField' => false,
            'titleFormat' => 'Smoke block',
        ]);
        $blockType->setFieldLayout($layout([], false));
        $saved($entries->saveEntryType($blockType), $blockType, 'block entry type');
        $report[] = 'created entry type ' . $handles['BLOCK_TYPE'];
    }

    $matrix = $fields->getFieldByHandle($handles['MATRIX_FIELD']);
    if (!$matrix instanceof FieldInterface) {
        $matrix = new Matrix(['name' => 'MCP smoke blocks', 'handle' => $handles['MATRIX_FIELD']]);
        $matrix->setEntryTypes([$blockType]);
        $saved($fields->saveField($matrix), $matrix, 'Matrix field');
        $report[] = 'created field ' . $handles['MATRIX_FIELD'];
    }

    $pageType = $entries->getEntryTypeByHandle($handles['ENTRY_TYPE']);
    if ($pageType === null) {
        $pageType = new EntryType(['name' => 'MCP smoke page', 'handle' => $handles['ENTRY_TYPE']]);
        $pageType->setFieldLayout($layout([$matrix], true));
        $saved($entries->saveEntryType($pageType), $pageType, 'page entry type');
        $report[] = 'created entry type ' . $handles['ENTRY_TYPE'];
    }

    $section = $entries->getSectionByHandle($handles['SECTION']);
    if ($section === null) {
        $section = new Section([
            'name' => 'MCP smoke',
            'handle' => $handles['SECTION'],
            'type' => Section::TYPE_CHANNEL,
            'siteSettings' => array_map(
                // URLs, so stimmt_entries_preview_url mints a link for real
                // rather than refusing a section without them.
                static fn ($site) => new Section_SiteSettings(['siteId' => $site->id, 'enabledByDefault' => true, 'hasUrls' => true, 'uriFormat' => 'mcp-smoke/{slug}']),
                Craft::$app->getSites()->getAllSites(),
            ),
        ]);
        $section->setEntryTypes([$pageType]);
        $saved($entries->saveSection($section), $section, 'section');
        $report[] = 'created section ' . $handles['SECTION'];
    }

    // The relation needs the section's uid as its source, so it comes after
    // the section and is added to the page type's layout afterwards.
    $relation = $fields->getFieldByHandle($handles['RELATION_FIELD']);
    if (!$relation instanceof FieldInterface) {
        $relation = new EntriesField([
            'name' => 'MCP smoke related',
            'handle' => $handles['RELATION_FIELD'],
            'sources' => ['section:' . $section->uid],
        ]);
        $saved($fields->saveField($relation), $relation, 'Entries field');
        $report[] = 'created field ' . $handles['RELATION_FIELD'];
    }

    // Required, so a draft that empties it is refused at publish (#76).
    $requiredField = $fields->getFieldByHandle($handles['REQUIRED_FIELD']);
    if (!$requiredField instanceof FieldInterface) {
        $requiredField = new PlainText(['name' => 'MCP smoke required', 'handle' => $handles['REQUIRED_FIELD']]);
        $saved($fields->saveField($requiredField), $requiredField, 'required field');
        $report[] = 'created field ' . $handles['REQUIRED_FIELD'];
    }

    // Rebuilt whenever a field is missing, so an install set up by an older
    // fixture gains the newer fields too.
    $pageLayout = $pageType->getFieldLayout();
    $missing = array_filter(
        [$handles['RELATION_FIELD'], $handles['REQUIRED_FIELD']],
        static fn (string $handle): bool => $pageLayout->getFieldByHandle($handle) === null,
    );
    if ($missing !== []) {
        $pageType->setFieldLayout($layout([$matrix, $relation, $requiredField], true, [$handles['REQUIRED_FIELD']]));
        $saved($entries->saveEntryType($pageType), $pageType, 'page entry type layout');
        $report[] = 'added ' . implode(', ', $missing) . ' to ' . $handles['ENTRY_TYPE'];
    }

    $existing = Entry::find()->section($handles['SECTION'])->slug($handles['FIXTURE_SLUG'])->status(null)->one();
    if ($existing === null) {
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $pageType->id,
            'title' => 'Smoke fixture',
            'slug' => $handles['FIXTURE_SLUG'],
        ]);
        // Saved live, so the required field needs a value.
        $entry->setFieldValue($handles['REQUIRED_FIELD'], 'fixture');
        $saved(Craft::$app->getElements()->saveElement($entry), $entry, 'seed entry');
        $report[] = 'created entry ' . $handles['FIXTURE_SLUG'];
    }

    // A standalone script never fires EVENT_AFTER_REQUEST, which is where
    // Craft persists project config. Without this the section rows exist while
    // the projectconfig table and the YAML know nothing of them.
    Craft::$app->getProjectConfig()->flush();
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, json_encode(['report' => $report === [] ? ['fixture already in place'] : $report]) . "\n");
