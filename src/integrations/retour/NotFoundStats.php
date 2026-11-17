<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\integrations\retour;

use craft\db\Query;
use craft\helpers\Db;
use DateTimeImmutable;
use stimmt\craft\Mcp\integrations\Lookback;
use yii\db\Expression;

/**
 * Retour's 404 statistics, read straight from the table it keeps them in.
 *
 * WHY a query rather than Retour's Statistics service: getAllStatistics()
 * has no site, date or handled filter and is capped by a display setting, and
 * getRecentStatistics() interpolates its arguments into SQL and has no limit
 * (craft-retour src/services/Statistics.php:53-103). Neither can page. The
 * table and columns are the ones Retour's own Install migration creates
 * (src/migrations/Install.php:131-155); Retour defines no constant for them.
 *
 * One row per URL: Retour increments hitCount on the same row for every hit
 * and overwrites the referrer and hitLastTime (Statistics.php:195-221), so the
 * hit count is the URL's lifetime total and the days window filters on its
 * most recent hit.
 */
final class NotFoundStats {
    public const string STATS_TABLE = '{{%retour_stats}}';

    public const string REDIRECTS_TABLE = '{{%retour_static_redirects}}';

    public function query(?int $siteId, DateTimeImmutable $since, bool $excludeHandled): Query {
        $query = (new Query())
            ->select([
                's.redirectSrcUrl', 's.referrerUrl', 's.hitCount', 's.hitLastTime', 's.handledByRetour', 's.siteId',
                'redirected' => $this->redirectFor()->select(new Expression('COUNT(*)')),
            ])
            ->from(['s' => self::STATS_TABLE])
            ->where(['>=', 's.hitLastTime', Db::prepareDateForDb($since)])
            ->orderBy(['s.hitCount' => SORT_DESC, 's.hitLastTime' => SORT_DESC, 's.id' => SORT_DESC]);

        if ($siteId !== null) {
            $query->andWhere(['s.siteId' => $siteId]);
        }

        if ($excludeHandled) {
            $query->andWhere(['s.handledByRetour' => false])
                ->andWhere(['not exists', $this->redirectFor()->select('r.id')]);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{url: string, hits: int, lastHit: ?string, referrer: ?string, handled: bool}
     */
    public static function row(array $row): array {
        return [
            // The query string goes, as for referrers: Retour keeps it unless
            // stripQueryStringFromStats is on, and it can hold tokens or emails.
            'url' => Referrer::withoutQuery((string) $row['redirectSrcUrl']) ?? '',
            'hits' => (int) $row['hitCount'],
            'lastHit' => Lookback::utc(is_string($row['hitLastTime']) ? $row['hitLastTime'] : null),
            'referrer' => Referrer::withoutQuery((string) ($row['referrerUrl'] ?? '')),
            'handled' => (bool) $row['handledByRetour'] || (int) $row['redirected'] > 0,
        ];
    }

    /**
     * An enabled exact-match static redirect for the 404's path, on its site
     * or on all sites: the same columns and site rule Retour's own lookup uses
     * (Redirects::getStaticRedirect, src/services/Redirects.php:483-506).
     *
     * WHY this as well as handledByRetour: that flag describes the URL's last
     * hit, so a redirect added since then is invisible to it until the next
     * visitor arrives. Regex redirects are not evaluated here; the response
     * says so.
     */
    private function redirectFor(): Query {
        return (new Query())
            ->from(['r' => self::REDIRECTS_TABLE])
            ->where(['r.redirectMatchType' => 'exactmatch', 'r.enabled' => true])
            ->andWhere('[[r.redirectSrcUrlParsed]] = [[s.redirectSrcUrl]]')
            ->andWhere(['or', '[[r.siteId]] = [[s.siteId]]', ['r.siteId' => null]]);
    }
}
