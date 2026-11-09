<?php

namespace KraftInk\Plugins\Support\Services\Imports\MineWeb;

use App\Kraft\Migration\ImportContext;
use App\Kraft\Migration\ImportedHtml;
use App\Kraft\Migration\MappingRegistry;
use Closure;
use Illuminate\Support\Str;

final class ImportMappings
{
    public function register(MappingRegistry $registry, array $replace, Closure $guard): void
    {
        $registry->register(
            'mineweb',
            'support__categories',
            $this->category(...),
            50,
            'support',
            $replace,
            guard: $guard,
        );
        $registry->register(
            'mineweb',
            'support__tickets',
            function (array $row, ImportContext $context): array {
                $result = $this->ticket($row, $context);
                $context->insert('support__messages', [
                    'ticket_id' => $result['target_id'],
                    'user_id' => $context->required('users', $row['author']),
                    'body' => ImportedHtml::clean($row['reponse_text']),
                    'internal' => false,
                    'created_at' => $row['created'] ?? now(),
                ]);

                return $result;
            },
            60,
            'support',
            dependencies: ['users', 'support__categories'],
        );
        $registry->register(
            'mineweb',
            'support__reply_tickets',
            $this->message(...),
            70,
            'support',
            dependencies: ['users', 'support__tickets'],
        );
        $registry->register('mineweb', 'support__settings', $this->legacySettings(...), 80, 'support');
    }

    private function category(array $row, ImportContext $context): array
    {
        return [
            'target_table' => 'support__categories',
            'target_id' => $context->insert('support__categories', [
                'name' => Str::limit(strip_tags($row['name']), 120, ''),
                'locale' => config('imports.locale'),
                'permission' => null,
                'position' => max(0, (int) ($row['position'] ?? 0)),
                'icon' => preg_match('/^[a-z0-9-]{1,60}$/D', $row['icon'] ?? '') ? $row['icon'] : 'life-preserver',
                'introduction' => ImportedHtml::clean($row['description'] ?? ''),
                'access' => 'members',
                'enabled' => false,
                'auto_close_hours' => null,
            ]),
        ];
    }

    private function ticket(array $row, ImportContext $context): array
    {
        $closed = (int) ($row['state'] ?? 0) === 2;
        $status = $closed ? 'closed' : 'open';
        if (!$closed && (int) ($row['state'] ?? 0) === 1) {
            $status = 'answered';
        }

        return [
            'target_table' => 'support__tickets',
            'target_id' => $context->insert('support__tickets', [
                'user_id' => $context->required('users', $row['author']),
                'category_id' => $context->required('support__categories', $row['categorie']),
                'subject' => Str::limit(strip_tags($row['subject']), 180, ''),
                'status' => $status,
                'priority' => 'normal',
                'assigned_to' => $context->mapped('users', $row['assignee_id'] ?? null),
                'closed_at' => $closed ? $row['closed_at'] ?? ($row['updated_at'] ?? ($row['created'] ?? now())) : null,
                'created_at' => $row['created'] ?? now(),
                'updated_at' => $row['updated_at'] ?? now(),
            ]),
        ];
    }

    private function message(array $row, ImportContext $context): array
    {
        return [
            'target_table' => 'support__messages',
            'target_id' => $context->insert('support__messages', [
                'user_id' => $context->required('users', $row['author']),
                'ticket_id' => $context->required('support__tickets', $row['ticket_id']),
                'body' => ImportedHtml::clean($row['reply']),
                'internal' => !(array_key_exists('type', $row) && in_array((int) $row['type'], [0, 1], true)),
                'created_at' => $row['created'] ?? now(),
                'updated_at' => $row['created'] ?? now(),
            ]),
        ];
    }

    private function legacySettings(array $row, ImportContext $context): array
    {
        if (!empty($row['suffix_reply'])) {
            $context->report('unsupported', [
                'plugin' => 'support',
                'table' => 'support__settings',
                'id' => $row['id'],
                'reason' => 'reply_suffix',
            ]);
        }

        return ['metadata' => ['archive_only' => true]];
    }
}
