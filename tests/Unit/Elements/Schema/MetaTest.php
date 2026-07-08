<?php

declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/vendor/yiisoft/yii2/Yii.php';

use craft\models\EntryType;
use stimmt\craft\Mcp\elements\schema\Meta;
use yii\base\Model;

/**
 * A plain Yii model standing in for an element: rules make postDate and title
 * safe under the live scenario, while denylisted internals also carry rules
 * (mirroring Craft, where dateCreated has a rule and would leak without the
 * denylist).
 */
class MetaFixture extends Model {
    public $title;

    public $postDate;

    public $dateCreated;

    public $id;

    public function rules(): array {
        return [
            [['title', 'postDate', 'dateCreated', 'id'], 'safe'],
        ];
    }
}

describe('Meta', function () {
    it('infers writable attributes and strips denylisted internals', function () {
        $meta = new Meta();

        expect($meta->writable(new MetaFixture()))->toBe(['title', 'postDate']);
    });

    it('reads entry-type gating flags', function () {
        $type = new EntryType(['hasTitleField' => false, 'showSlugField' => true, 'showStatusField' => false]);

        expect((new Meta())->entryFlags($type))->toBe([
            'hasTitleField' => false,
            'showSlugField' => true,
            'showStatusField' => false,
        ]);
    });
});
