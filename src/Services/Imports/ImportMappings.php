<?php

namespace KraftInk\Plugins\Support\Services\Imports;

use App\Kraft\Migration\MappingRegistry;
use KraftInk\Plugins\Support\Services\Imports\Azuriom\ImportMappings as AzuriomImportMappings;
use KraftInk\Plugins\Support\Services\Imports\KraftInk\ImportMappings as KraftInkImportMappings;
use KraftInk\Plugins\Support\Services\Imports\MineWeb\ImportMappings as MineWebImportMappings;
use RuntimeException;

final class ImportMappings
{
    private const array TABLES = [
        'support__message_revisions' => [],
        'support__files' => [],
        'support__drafts' => [],
        'notification_deliveries' => ['owner' => ['support']],
        'support__templates' => [],
        'support__category_roles' => [],
        'support__field_values' => [],
        'support__ticket_events' => [],
        'support__messages' => [],
        'support__tickets' => [],
        'support__fields' => [],
        'support__categories' => [],
    ];

    public function register(MappingRegistry $registry): void
    {
        new AzuriomImportMappings()->register($registry, self::TABLES, self::guard(...));
        new MineWebImportMappings()->register($registry, self::TABLES, self::guard(...));
        new KraftInkImportMappings()->register($registry, self::TABLES, self::guard(...));
    }

    private static function guard($target, array $options): void
    {
        if (
            ($options['mode'] ?? 'merge') === 'replace' &&
            $target->getSchemaBuilder()->hasTable('notification_deliveries') &&
            $target
                ->table('notification_deliveries')
                ->where('owner', 'support')
                ->where('status', 'sending')
                ->where('started_at', '>=', now()->subMinutes(10))
                ->exists()
        ) {
            throw new RuntimeException(__('support::admin.operations.import_busy'));
        }
    }
}
