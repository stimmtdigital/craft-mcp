<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../Fixtures/CraftStub.php';
require_once dirname(__DIR__, 4) . '/vendor/yiisoft/yii2/Yii.php';

use craft\fields\Assets;
use craft\fields\Categories;
use craft\fields\Entries;
use craft\fields\Tags;
use craft\fields\Users;
use craft\models\CategoryGroup;
use craft\models\Section;
use craft\models\TagGroup;
use craft\models\UserGroup;
use craft\models\Volume;
use craft\models\VolumeFolder;
use stimmt\craft\Mcp\elements\schema\Target;

describe('Target', function () {
    beforeEach(function () {
        $this->originalApp = Craft::$app;
        Craft::$app = new class () {
            public function getEntries(): object {
                return new class () {
                    public function getSectionByUid(string $uid): ?Section {
                        // previewTargets is supplied because Section::init()
                        // translates a default one otherwise, which needs an
                        // application this test deliberately does not boot.
                        return $uid === 'pages-uid'
                            ? new Section(['handle' => 'pages', 'previewTargets' => []])
                            : null;
                    }
                };
            }

            public function getCategories(): object {
                return new class () {
                    public function getGroupByUid(string $uid): ?CategoryGroup {
                        return $uid === 'topics-uid' ? new CategoryGroup(['handle' => 'topics']) : null;
                    }
                };
            }

            public function getUserGroups(): object {
                return new class () {
                    public function getGroupByUid(string $uid): ?UserGroup {
                        return $uid === 'editors-uid' ? new UserGroup(['handle' => 'editors']) : null;
                    }
                };
            }

            public function getTags(): object {
                return new class () {
                    public function getTagGroupByUid(string $uid): ?TagGroup {
                        return $uid === 'labels-uid' ? new TagGroup(['handle' => 'labels']) : null;
                    }
                };
            }

            public function getVolumes(): object {
                return new class () {
                    public function getVolumeByUid(string $uid): ?Volume {
                        return $uid === 'images-uid' ? new Volume(['handle' => 'images', 'id' => 3]) : null;
                    }

                    /** VolumeFolder::getVolume() reaches back through the service for its own volume. */
                    public function getVolumeById(int $id): ?Volume {
                        return $id === 3 ? new Volume(['handle' => 'images', 'id' => 3]) : null;
                    }
                };
            }

            public function getAssets(): object {
                return new class () {
                    public function getFolderByUid(string $uid): ?VolumeFolder {
                        return match ($uid) {
                            'logos-uid' => new VolumeFolder(['id' => 2, 'volumeId' => 3, 'path' => 'logos/']),
                            'root-uid' => new VolumeFolder(['id' => 1, 'volumeId' => 3, 'path' => '']),
                            // Craft's temporary upload folder: a real folder
                            // with no volume behind it.
                            'temp-uid' => new VolumeFolder(['id' => 3]),
                            default => null,
                        };
                    }
                };
            }
        };
    });

    afterEach(function () {
        Craft::$app = $this->originalApp;
    });

    // The defect this exists for: the one field telling a caller WHICH section
    // a relation accepts answered with "section:70b2614b-639a-4de2-8c11-...",
    // which is not a word the write side understands.
    it('reports each source as the natural-key parts it pins down', function () {
        $field = new Entries(['handle' => 'related', 'sources' => ['section:pages-uid']]);

        expect((new Target())->of($field))->toBe([
            'elementType' => craft\elements\Entry::class,
            'sources' => [['section' => 'pages']],
        ]);
    });

    // Craft's own named sets state a rule instead of naming a container, so
    // there is no handle to resolve and nothing to resolve it from.
    it('keeps a named set as the set it is', function () {
        $field = new Entries(['handle' => 'related', 'sources' => ['singles', 'section:pages-uid']]);

        expect((new Target())->of($field)['sources'])->toBe([['set' => 'singles'], ['section' => 'pages']]);
    });

    // A source nothing answers to is the install's stale data; passing the uid
    // back under the same key as a working source would make it this server's
    // error, since it reads as a section the caller could go and use.
    it('names a dead source unresolved instead of passing the uid through', function () {
        $field = new Entries(['handle' => 'related', 'sources' => ['section:pages-uid', 'section:gone-uid']]);

        expect((new Target())->of($field)['sources'])->toBe([
            ['section' => 'pages'],
            ['unresolved' => 'section:gone-uid'],
        ]);
    });

    it('keeps the raw key in the unresolved entry, since that is what finds the field setting', function () {
        $field = new Entries(['handle' => 'related', 'sources' => ['section:70b2614b-639a-4de2-8c11-3851d36d6f8c']]);

        expect((new Target())->of($field)['sources'][0]['unresolved'])
            ->toBe('section:70b2614b-639a-4de2-8c11-3851d36d6f8c');
    });

    // Both a category group and a user group are stored as "group:<uid>".
    it('reads a group source against the type the field relates to', function () {
        $users = new Users(['handle' => 'people', 'sources' => ['group:editors-uid', 'group:topics-uid']]);

        expect((new Target())->of($users))->toBe([
            'elementType' => craft\elements\User::class,
            'sources' => [['group' => 'editors'], ['unresolved' => 'group:topics-uid']],
        ]);
    });

    // A categories field allows exactly one source, so Craft stores it under
    // the singular `source` and leaves `sources` at its untouched '*' default.
    // Reading the plural one reported a field pinned to one group as accepting
    // every group.
    it('reads the singular setting for a field that allows one source', function () {
        $field = new Categories(['handle' => 'topic', 'source' => 'group:topics-uid']);

        expect($field->sources)->toBe('*')
            ->and((new Target())->of($field)['sources'])->toBe([['group' => 'topics']]);
    });

    // Each container prefix reads through the service that owns it, and an
    // asset folder pins down the path inside the volume as well, which is the
    // rest of the asset key, dropped at the root exactly where the key drops
    // it.
    it('resolves every source prefix it has a lookup for', function (object $field, array $sources) {
        expect((new Target())->of($field)['sources'])->toBe($sources);
    })->with([
        'volume' => [fn () => new Assets(['handle' => 'media', 'sources' => ['volume:images-uid']]), [['volume' => 'images']]],
        'asset folder' => [fn () => new Assets(['handle' => 'media', 'sources' => ['folder:logos-uid']]), [['volume' => 'images', 'path' => 'logos/']]],
        'volume root folder' => [fn () => new Assets(['handle' => 'media', 'sources' => ['folder:root-uid']]), [['volume' => 'images']]],
        'tag group' => [fn () => new Tags(['handle' => 'labels', 'source' => 'taggroup:labels-uid']), [['group' => 'labels']]],
    ]);

    // The temporary upload folder is a real folder with no volume behind it,
    // and getVolume() would answer with Craft's stand-in rather than with a
    // place a relation can point at.
    it('resolves nothing for a folder with no volume', function () {
        $field = new Assets(['handle' => 'media', 'sources' => ['folder:temp-uid']]);

        expect((new Target())->of($field)['sources'])->toBe([['unresolved' => 'folder:temp-uid']]);
    });

    // A prefix belonging to a plugin's own element type is not dead, it is
    // unreadable from here, and the same key says so without claiming which.
    it('resolves nothing for a prefix it has no lookup for', function () {
        $field = new Entries(['handle' => 'related', 'sources' => ['productType:whatever-uid']]);

        expect((new Target())->of($field)['sources'])->toBe([['unresolved' => 'productType:whatever-uid']]);
    });

    it('reports no restriction at all as the one thing Craft stores it as', function () {
        $unrestricted = new Entries(['handle' => 'related']);
        $noSourcePicked = new Categories(['handle' => 'topic']);

        expect((new Target())->of($unrestricted)['sources'])->toBe('*')
            ->and((new Target())->of($noSourcePicked)['sources'])->toBe('*');
    });
});
