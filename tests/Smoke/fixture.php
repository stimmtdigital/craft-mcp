<?php

declare(strict_types=1);

/**
 * Installs the content model the smoke plan is written against.
 *
 *   php tests/Smoke/fixture.php     from a plugin checkout inside a Craft project
 *
 * Creates one channel section (Plan::SECTION, with URLs) with one entry type
 * (Plan::ENTRY_TYPE) carrying a Matrix field (Plan::MATRIX_FIELD, one block
 * type, Plan::BLOCK_TYPE) and an Entries field (Plan::RELATION_FIELD) that
 * relates back to the same section, plus one published entry for the read
 * steps to find. Everything is named for the harness, so it cannot collide
 * with a site's real content.
 *
 * WHY a script rather than a list of control-panel clicks: the baselines are
 * only comparable between installs that share this exact model, and a script
 * is the one description of it that cannot drift from what was clicked.
 *
 * Idempotent: anything that already exists is reused. It writes project
 * config, so on a client site keep the resulting config/project changes out of
 * that site's commits.
 */

namespace stimmt\craft\Mcp\Tests\Smoke;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Entries as EntriesField;
use craft\fields\Matrix;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use RuntimeException;

require_once __DIR__ . '/Plan.php';

$root = __DIR__;
do {
    $root = dirname($root);
} while ($root !== '/' && !(is_file($root . '/bootstrap.php') && is_file($root . '/craft')));

if ($root === '/') {
    fwrite(STDERR, 'No Craft project found above ' . __DIR__ . "\n");
    exit(1);
}

chdir($root);
require_once $root . '/bootstrap.php';
require $root . '/vendor/craftcms/cms/bootstrap/console.php';

$saved = static function (bool $ok, object $model, string $what): void {
    if (!$ok) {
        $errors = method_exists($model, 'getErrors') ? json_encode($model->getErrors()) : '';

        throw new RuntimeException("Could not save {$what}: {$errors}");
    }
};

$layout = static function (array $fields, bool $withTitle): FieldLayout {
    $layout = new FieldLayout(['type' => Entry::class]);
    $elements = $withTitle ? [new EntryTitleField()] : [];
    foreach ($fields as $field) {
        $elements[] = new CustomField($field);
    }
    $layout->setTabs([new FieldLayoutTab(['name' => 'Content', 'layout' => $layout, 'elements' => $elements])]);

    return $layout;
};

$entries = Craft::$app->getEntries();
$fields = Craft::$app->getFields();
$report = [];

// The block type has no fields and no title of its own: create_nested_entry
// is called with nothing but owner, field and type.
$blockType = $entries->getEntryTypeByHandle(Plan::BLOCK_TYPE);
if ($blockType === null) {
    $blockType = new EntryType([
        'name' => 'MCP smoke block',
        'handle' => Plan::BLOCK_TYPE,
        'hasTitleField' => false,
        'titleFormat' => 'Smoke block',
    ]);
    $blockType->setFieldLayout($layout([], false));
    $saved($entries->saveEntryType($blockType), $blockType, 'block entry type');
    $report[] = 'created entry type ' . Plan::BLOCK_TYPE;
}

$matrix = $fields->getFieldByHandle(Plan::MATRIX_FIELD);
if (!$matrix instanceof FieldInterface) {
    $matrix = new Matrix(['name' => 'MCP smoke blocks', 'handle' => Plan::MATRIX_FIELD]);
    $matrix->setEntryTypes([$blockType]);
    $saved($fields->saveField($matrix), $matrix, 'Matrix field');
    $report[] = 'created field ' . Plan::MATRIX_FIELD;
}

$pageType = $entries->getEntryTypeByHandle(Plan::ENTRY_TYPE);
if ($pageType === null) {
    $pageType = new EntryType(['name' => 'MCP smoke page', 'handle' => Plan::ENTRY_TYPE]);
    $pageType->setFieldLayout($layout([$matrix], true));
    $saved($entries->saveEntryType($pageType), $pageType, 'page entry type');
    $report[] = 'created entry type ' . Plan::ENTRY_TYPE;
}

$section = $entries->getSectionByHandle(Plan::SECTION);
if ($section === null) {
    $section = new Section([
        'name' => 'MCP smoke',
        'handle' => Plan::SECTION,
        'type' => Section::TYPE_CHANNEL,
        'siteSettings' => array_map(
            // URLs, so every write's previewUrl is minted for real and the
            // baselines pin its shape; a URL-less section would record null.
            static fn ($site) => new Section_SiteSettings(['siteId' => $site->id, 'enabledByDefault' => true, 'hasUrls' => true, 'uriFormat' => 'mcp-smoke/{slug}']),
            Craft::$app->getSites()->getAllSites(),
        ),
    ]);
    $section->setEntryTypes([$pageType]);
    $saved($entries->saveSection($section), $section, 'section');
    $report[] = 'created section ' . Plan::SECTION;
}

// The relation needs the section's uid as its source, so it comes after the
// section and is added to the page type's layout afterwards.
$relation = $fields->getFieldByHandle(Plan::RELATION_FIELD);
if (!$relation instanceof FieldInterface) {
    $relation = new EntriesField([
        'name' => 'MCP smoke related',
        'handle' => Plan::RELATION_FIELD,
        'sources' => ['section:' . $section->uid],
    ]);
    $saved($fields->saveField($relation), $relation, 'Entries field');
    $report[] = 'created field ' . Plan::RELATION_FIELD;
}

if ($pageType->getFieldLayout()->getFieldByHandle(Plan::RELATION_FIELD) === null) {
    $pageType->setFieldLayout($layout([$matrix, $relation], true));
    $saved($entries->saveEntryType($pageType), $pageType, 'page entry type layout');
    $report[] = 'added ' . Plan::RELATION_FIELD . ' to ' . Plan::ENTRY_TYPE;
}

$existing = Entry::find()->section(Plan::SECTION)->slug('smoke-fixture')->status(null)->one();
if ($existing === null) {
    $entry = new Entry([
        'sectionId' => $section->id,
        'typeId' => $pageType->id,
        'title' => 'Smoke fixture',
        'slug' => 'smoke-fixture',
    ]);
    $saved(Craft::$app->getElements()->saveElement($entry), $entry, 'seed entry');
    $report[] = 'created entry smoke-fixture';
}

// A standalone script never fires EVENT_AFTER_REQUEST, which is where Craft
// persists project config. Without this the section rows exist while the
// projectconfig table and the YAML know nothing of them.
Craft::$app->getProjectConfig()->flush();

fwrite(STDOUT, ($report === [] ? 'fixture already in place' : implode("\n", $report)) . "\n");
