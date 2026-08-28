<?php

declare(strict_types=1);

require_once __DIR__ . '/../../Fixtures/CraftStub.php';
require_once dirname(__DIR__, 3) . '/vendor/yiisoft/yii2/Yii.php';

use craft\models\CategoryGroup;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\UserGroup;
use craft\models\Volume;
use craft\models\VolumeFolder;
use Mcp\Exception\ToolCallException;
use stimmt\craft\Mcp\support\HandleResolver;

describe('HandleResolver', function () {
    beforeEach(function () {
        $this->originalApp = Craft::$app;
        Craft::$app = new class () {
            /** One section, 'pages', allowing one entry type, 'page'. */
            public function getEntries(): object {
                return new class () {
                    public function getSectionByHandle(string $handle): ?Section {
                        if ($handle !== 'pages') {
                            return null;
                        }

                        // previewTargets is supplied because Section::init()
                        // translates a default one otherwise, which needs an
                        // application this test deliberately does not boot.
                        $section = new Section(['handle' => 'pages', 'id' => 2, 'previewTargets' => []]);
                        $section->setEntryTypes([new EntryType(['handle' => 'page', 'id' => 8])]);

                        return $section;
                    }

                    public function getEntryTypeByHandle(string $handle): ?EntryType {
                        return $handle === 'page' ? new EntryType(['handle' => 'page', 'id' => 8]) : null;
                    }

                    /** Section::setEntryTypes() runs each type past the service. */
                    public function getEntryType(mixed $entryType): mixed {
                        return $entryType;
                    }
                };
            }

            public function getVolumes(): object {
                return new class () {
                    public function getVolumeByHandle(string $handle): ?Volume {
                        return $handle === 'images' ? new Volume(['handle' => 'images', 'id' => 3]) : null;
                    }
                };
            }

            public function getCategories(): object {
                return new class () {
                    public function getGroupByHandle(string $handle): ?CategoryGroup {
                        return $handle === 'topics' ? new CategoryGroup(['handle' => 'topics', 'id' => 5]) : null;
                    }

                    public function getAllGroups(): array {
                        return [new CategoryGroup(['handle' => 'topics'])];
                    }
                };
            }

            /** An install with no user groups at all, which is what an unlicensed edition looks like. */
            public function getUserGroups(): object {
                return new class () {
                    public function getGroupByHandle(string $handle): ?UserGroup {
                        return null;
                    }

                    public function getAllGroups(): array {
                        return [];
                    }
                };
            }

            public function getAssets(): object {
                return new class () {
                    public function getFolderById(int $id): ?VolumeFolder {
                        return $id === 1 ? new VolumeFolder(['id' => 1, 'name' => 'Images']) : null;
                    }
                };
            }
        };
    });

    afterEach(function () {
        Craft::$app = $this->originalApp;
    });

    it('reads null as "no filter" rather than as a lookup', function () {
        expect(HandleResolver::volume(null))->toBeNull()
            ->and(HandleResolver::categoryGroup(null))->toBeNull()
            ->and(HandleResolver::userGroup(null))->toBeNull()
            ->and(HandleResolver::userStatus(null))->toBeNull()
            ->and(HandleResolver::assetFolder(null))->toBeNull()
            ->and(HandleResolver::section(null))->toBeNull()
            ->and(HandleResolver::entryType(null))->toBeNull()
            ->and(HandleResolver::entryStatus(null))->toBeNull();
    });

    it('resolves a handle the install has', function () {
        expect(HandleResolver::volume('images')?->id)->toBe(3)
            ->and(HandleResolver::categoryGroup('topics')?->id)->toBe(5)
            ->and(HandleResolver::assetFolder(1)?->name)->toBe('Images')
            ->and(HandleResolver::section('pages')?->id)->toBe(2)
            ->and(HandleResolver::entryType('page')?->id)->toBe(8);
    });

    // The defect this exists for: Craft answers an unknown handle with an
    // empty result set, which an agent reports as "there are none".
    it('refuses an unknown volume, pointing at the tool that lists them', function () {
        expect(fn () => HandleResolver::volume('nope'))
            ->toThrow(ToolCallException::class, "Volume 'nope' not found. Use list_volumes for available handles.");
    });

    it('refuses an unknown category group, naming the handles there are', function () {
        expect(fn () => HandleResolver::categoryGroup('nope'))
            ->toThrow(ToolCallException::class, "Category group 'nope' not found. Available handles: topics.");
    });

    it('says so plainly when the install has none of that thing', function () {
        expect(fn () => HandleResolver::userGroup('admins'))
            ->toThrow(ToolCallException::class, "User group 'admins' not found. This install has none.");
    });

    // The user status vocabulary itself is not exercised here: User::statuses()
    // translates its labels, which needs a booted application rather than a
    // stub. The tools verify it against the live install.

    it('refuses a folder id nothing answers to', function () {
        expect(fn () => HandleResolver::assetFolder(999999))
            ->toThrow(ToolCallException::class, 'Asset folder 999999 not found. Use list_asset_folders for available folder ids.');
    });

    // "How many entries in section X" answering 0 is a wrong answer when the
    // truthful one is "there is no X". Every surface that takes a section
    // handle now asks here, so they all answer it in these words.
    it('refuses an unknown section, naming the tool that lists them', function () {
        expect(fn () => HandleResolver::section('nope'))
            ->toThrow(ToolCallException::class, "Section 'nope' not found. Use list_sections for available handles.");
    });

    it('refuses an entry type the install does not have, pointing at the schema tool', function () {
        expect(fn () => HandleResolver::entryType('nope'))
            ->toThrow(
                ToolCallException::class,
                "Entry type 'nope' not found. Use describe_entry_schema for the entry types a section allows.",
            );
    });

    // With a section in hand the useful refusal names the types THAT section
    // allows, which is also the only check a write can trust.
    it('refuses an entry type the section does not allow, naming the ones it does', function () {
        expect(fn () => HandleResolver::entryType('nope', HandleResolver::section('pages')))
            ->toThrow(ToolCallException::class, "Entry type 'nope' in section 'pages' not found. Available handles: page.");
    });

    it('resolves an entry type against the section that allows it', function () {
        expect(HandleResolver::entryType('page', HandleResolver::section('pages'))?->id)->toBe(8);
    });

    it('accepts every entry status the tool descriptions document', function (?string $status) {
        expect(HandleResolver::entryStatus($status))->toBe($status);
    })->with([[null], ['live'], ['pending'], ['expired'], ['disabled'], ['any']]);

    it('refuses a status that is not one, listing the ones that are', function () {
        expect(fn () => HandleResolver::entryStatus('published'))
            ->toThrow(ToolCallException::class, "Entry status 'published' not found");
    });

    it('spells the alternatives out in the refusal', function () {
        try {
            HandleResolver::entryStatus('published');
        } catch (ToolCallException $e) {
            expect($e->getMessage())->toContain('Use one of: live, pending, expired, disabled, any.');

            return;
        }

        throw new RuntimeException('Expected a ToolCallException for an unknown status');
    });
});
