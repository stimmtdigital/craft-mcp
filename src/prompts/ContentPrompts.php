<?php

declare(strict_types=1);

namespace stimmt\craft\Mcp\prompts;

use Craft;
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\models\Section;
use craft\models\Volume;
use craft\services\Config;
use craft\services\Entries;
use craft\services\Fields;
use craft\services\Volumes;
use Mcp\Capability\Attribute\McpPrompt;
use stimmt\craft\Mcp\attributes\McpPromptMeta;
use stimmt\craft\Mcp\enums\PromptCategory;
use stimmt\craft\Mcp\services\SchemaHelper;

/**
 * MCP prompts for content analysis and management.
 *
 * @author Max van Essen <support@stimmt.digital>
 */
final class ContentPrompts {
    /**
     * Generate a prompt for analyzing content health across the site.
     *
     * @return array{array{role: string, content: string}}
     */
    #[McpPrompt(
        name: 'content_health_analysis',
        description: 'Analyze the content health of a Craft CMS installation, including entry statistics, status distribution, and freshness.',
    )]
    #[McpPromptMeta(category: PromptCategory::CONTENT)]
    public function contentHealthAnalysis(): array {
        /** @var Entries $entriesService */
        $entriesService = Craft::$app->getEntries();

        /** @var Section[] $sections */
        $sections = $entriesService->getAllSections();

        /** @var list<array{section: string, name: string, live: int, disabled: int, pending: int, expired: int, drafts: int, total: int, lastUpdated: string|null}> $healthData */
        $healthData = array_values(array_map($this->buildSectionHealthData(...), $sections));
        $totals = $this->calculateTotals($healthData);

        $healthJson = json_encode([
            'summary' => $totals,
            'sections' => $healthData,
        ], JSON_PRETTY_PRINT);

        return $this->promptResponse(<<<PROMPT
Analyze this content health report for a Craft CMS installation:

```json
{$healthJson}
```

Please provide insights on:
1. Overall content health score and assessment
2. Sections that may need attention (high disabled/expired ratio, stale content)
3. Content freshness analysis
4. Workflow efficiency (draft vs published ratio)
5. Recommendations for content maintenance
PROMPT);
    }

    /**
     * Generate a prompt for content audit.
     *
     * @return array{array{role: string, content: string}}
     */
    #[McpPrompt(
        name: 'content_audit',
        description: 'Generate a comprehensive content audit report for review and optimization.',
    )]
    #[McpPromptMeta(category: PromptCategory::WORKFLOW)]
    public function contentAudit(): array {
        /** @var Fields $fieldsService */
        $fieldsService = Craft::$app->getFields();

        /** @var FieldInterface[] $allFields */
        $allFields = $fieldsService->getAllFields();

        $auditData = [
            'sections' => $this->buildSectionAuditData(),
            'fieldsByType' => $this->buildFieldTypeDistribution(),
            'totalFields' => count($allFields),
            'assetUsage' => $this->buildAssetUsageData(),
        ];

        $auditJson = json_encode($auditData, JSON_PRETTY_PRINT);

        return $this->promptResponse(<<<PROMPT
Perform a content audit based on this Craft CMS data:

```json
{$auditJson}
```

Please provide:
1. Executive summary of the content structure
2. Content volume analysis per section
3. Field type distribution and potential optimizations
4. Asset management assessment
5. SEO considerations (sections with/without URLs)
6. Recommendations for content architecture improvements
7. Potential cleanup opportunities
PROMPT);
    }

    /**
     * Generate a prompt for debugging content issues.
     *
     * @return array{array{role: string, content: string}}
     */
    #[McpPrompt(
        name: 'debug_content_issue',
        description: 'Help debug and diagnose content-related issues in Craft CMS.',
    )]
    #[McpPromptMeta(category: PromptCategory::DEBUGGING)]
    public function debugContentIssue(
        string $issueDescription,
    ): array {
        $systemInfo = $this->getSystemInfo();

        return $this->promptResponse(<<<PROMPT
I'm experiencing a content-related issue in Craft CMS and need help debugging it.

**Issue Description:**
{$issueDescription}

**System Information:**
- Craft Version: {$systemInfo['craftVersion']}
- PHP Version: {$systemInfo['phpVersion']}
- Dev Mode: {$systemInfo['devMode']}

Please help me:
1. Identify potential causes of this issue
2. Suggest diagnostic steps to narrow down the problem
3. Recommend tools to use (read_logs, get_last_error, get_file_problems)
4. Provide possible solutions or workarounds
5. Identify if this might be a configuration, data, or code issue

What additional information would you need to diagnose this issue?
PROMPT);
    }

    /**
     * Build health data for a single section.
     *
     * @return array{section: string, name: string, live: int, disabled: int, pending: int, expired: int, drafts: int, total: int, lastUpdated: string|null}
     */
    private function buildSectionHealthData(Section $section): array {
        $handle = $section->handle ?? '';
        $live = $this->getEntryCount($handle, 'live');
        $disabled = $this->getEntryCount($handle, 'disabled');
        $pending = $this->getEntryCount($handle, 'pending');
        $expired = $this->getEntryCount($handle, 'expired');
        $drafts = $this->getDraftCount($handle);
        $lastUpdated = $this->getLastUpdatedDate($handle);

        return [
            'section' => $handle,
            'name' => $section->name ?? '',
            'live' => $live,
            'disabled' => $disabled,
            'pending' => $pending,
            'expired' => $expired,
            'drafts' => $drafts,
            'total' => $live + $disabled + $pending + $expired,
            'lastUpdated' => $lastUpdated,
        ];
    }

    /**
     * Calculate totals from section health data.
     *
     * @param array<int, array{section: string, name: string, live: int, disabled: int, pending: int, expired: int, drafts: int, total: int, lastUpdated: string|null}> $healthData
     * @return array{totalSections: int, totalLive: int, totalDisabled: int, totalDrafts: int, totalPending: int, totalExpired: int}
     */
    private function calculateTotals(array $healthData): array {
        return [
            'totalSections' => count($healthData),
            'totalLive' => array_sum(array_column($healthData, 'live')),
            'totalDisabled' => array_sum(array_column($healthData, 'disabled')),
            'totalDrafts' => array_sum(array_column($healthData, 'drafts')),
            'totalPending' => array_sum(array_column($healthData, 'pending')),
            'totalExpired' => array_sum(array_column($healthData, 'expired')),
        ];
    }

    /**
     * Get entry count for a section with a specific status.
     */
    private function getEntryCount(string $section, string $status): int {
        return (int) Entry::find()
            ->section($section)
            ->status($status)
            ->count();
    }

    /**
     * Get draft count for a section.
     */
    private function getDraftCount(string $section): int {
        return (int) Entry::find()
            ->section($section)
            ->drafts()
            ->count();
    }

    /**
     * Get the last updated date for a section.
     */
    private function getLastUpdatedDate(string $section): ?string {
        /** @var Entry|null $lastEntry */
        $lastEntry = Entry::find()
            ->section($section)
            ->status(null)
            ->orderBy(['dateUpdated' => SORT_DESC])
            ->one();

        return $lastEntry?->dateUpdated?->format('Y-m-d H:i:s');
    }

    /**
     * Build section audit data.
     *
     * @return list<array{handle: string, name: string, type: string, entryCount: int, hasUris: bool}>
     */
    private function buildSectionAuditData(): array {
        /** @var Entries $entriesService */
        $entriesService = Craft::$app->getEntries();

        /** @var Section[] $sections */
        $sections = $entriesService->getAllSections();

        return array_values(array_map(
            fn (Section $section): array => [
                'handle' => $section->handle ?? '',
                'name' => $section->name ?? '',
                'type' => $section->type ?? 'channel',
                'entryCount' => (int) Entry::find()->section($section->handle ?? '')->status(null)->count(),
                'hasUris' => $this->sectionHasUrls($section),
            ],
            $sections,
        ));
    }

    /**
     * Check if a section has URLs configured.
     */
    private function sectionHasUrls(Section $section): bool {
        return array_any($section->getSiteSettings(), fn ($settings): bool => $settings->hasUrls);
    }

    /**
     * Build field type distribution.
     *
     * @return array<string, int>
     */
    private function buildFieldTypeDistribution(): array {
        /** @var Fields $fieldsService */
        $fieldsService = Craft::$app->getFields();

        /** @var FieldInterface[] $fields */
        $fields = $fieldsService->getAllFields();

        $distribution = [];

        foreach ($fields as $field) {
            $type = SchemaHelper::getFieldTypeName($field);
            $distribution[$type] = isset($distribution[$type]) ? $distribution[$type] + 1 : 1;
        }

        return $distribution;
    }

    /**
     * Build asset usage data.
     *
     * @return list<array{volume: string, name: string, assetCount: int}>
     */
    private function buildAssetUsageData(): array {
        /** @var Volumes $volumesService */
        $volumesService = Craft::$app->getVolumes();

        /** @var Volume[] $volumes */
        $volumes = $volumesService->getAllVolumes();

        return array_values(array_map(
            fn (Volume $volume): array => [
                'volume' => $volume->handle ?? '',
                'name' => $volume->name ?? '',
                'assetCount' => (int) Asset::find()->volume($volume->handle ?? '')->count(),
            ],
            $volumes,
        ));
    }

    /**
     * Get system information for debugging.
     *
     * @return array{craftVersion: string, phpVersion: string, devMode: string}
     */
    private function getSystemInfo(): array {
        /** @var Config $configService */
        $configService = Craft::$app->getConfig();
        $generalConfig = $configService->getGeneral();

        return [
            'craftVersion' => Craft::$app->getVersion(),
            'phpVersion' => PHP_VERSION,
            'devMode' => $generalConfig->devMode ? 'enabled' : 'disabled',
        ];
    }

    /**
     * Create a prompt response.
     *
     * @return array{array{role: string, content: string}}
     */
    private function promptResponse(string $content): array {
        return [[
            'role' => 'user',
            'content' => $content,
        ]];
    }
}
