<?php

namespace KraftInk\Plugins\Support\Services\Imports\KraftInk;

use App\Kraft\Migration\ImportContext;
use App\Kraft\Migration\ImportedHtml;
use App\Kraft\Migration\MappingRegistry;
use Closure;
use KraftInk\Plugins\Support\Models\Field;

final class ImportMappings
{
    private const array FIELDS = [
        'support__categories' => [
            'name',
            'locale',
            'position',
            'icon',
            'access',
            'introduction',
            'structured_form_only',
            'first_response_minutes',
            'resolution_minutes',
            'auto_close_unanswered',
        ],
        'support__tickets' => [
            'subject',
            'status',
            'priority',
            'created_at',
            'updated_at',
            'deleted_at',
            'first_staff_reply_at',
            'last_staff_reply_at',
            'closed_at',
            'reopened_at',
        ],
        'support__messages' => ['body', 'internal', 'created_at', 'updated_at', 'deleted_at'],
        'support__message_revisions' => ['body', 'deleted_at', 'anonymized', 'created_at'],
        'support__fields' => ['key', 'label', 'help_text', 'type', 'options', 'required', 'staff_only', 'enabled', 'position'],
        'support__field_values' => ['field_label', 'staff_only', 'value'],
        'support__templates' => ['title', 'locale', 'kind', 'body', 'position', 'created_at', 'updated_at'],
        'support__ticket_events' => ['event', 'created_at'],
    ];

    public function register(MappingRegistry $registry, array $replacements, Closure $guard): void
    {
        $registry->register(
            'kraftink',
            'support__categories',
            fn ($row, $context) => $this->row('support__categories', $row, $context),
            50,
            'support',
            $replacements,
            guard: $guard,
        );
        $definitions = [
            'support__fields' => [55, ['category_id' => 'support__categories']],
            'support__tickets' => [
                60,
                ['category_id' => 'support__categories', 'user_id' => 'users', 'assigned_to' => 'users'],
            ],
            'support__templates' => [60, ['category_id' => 'support__categories']],
            'support__messages' => [65, ['ticket_id' => 'support__tickets', 'user_id' => 'users']],
            'support__field_values' => [65, ['ticket_id' => 'support__tickets', 'field_id' => 'support__fields']],
            'support__ticket_events' => [65, ['ticket_id' => 'support__tickets', 'actor_id' => 'users']],
            'support__message_revisions' => [70, ['message_id' => 'support__messages', 'user_id' => 'users']],
        ];
        foreach ($definitions as $table => [$priority, $relations]) {
            $registry->register(
                'kraftink',
                $table,
                fn ($row, $context) => $this->row($table, $row, $context, $relations),
                $priority,
                'support',
                dependencies: array_values(array_unique($relations)),
            );
        }
        $registry->register(
            'kraftink',
            'support__category_roles',
            function (array $row, ImportContext $context): array {
                $data = [
                    'category_id' => $context->required('support__categories', $row['category_id']),
                    'role_id' => $context->required('roles', $row['role_id']),
                ];
                $context->target->table('support__category_roles')->insert($data);

                return ['target_table' => 'support__category_roles', 'metadata' => $data];
            },
            55,
            'support',
            dependencies: ['support__categories', 'roles'],
        );
    }

    private function row(string $table, array $row, ImportContext $context, array $relations = []): array
    {
        $data = array_intersect_key($row, array_flip(self::FIELDS[$table]));
        foreach ($relations as $column => $source) {
            $data[$column] = isset($row[$column]) ? $context->required($source, $row[$column]) : null;
        }
        if (in_array('locale', self::FIELDS[$table], true)) {
            $data['locale'] = array_key_exists($row['locale'] ?? '', config('kraft.locales'))
                ? $row['locale']
                : config('imports.locale');
        }
        foreach (['body', 'introduction'] as $column) {
            if (isset($data[$column])) {
                $data[$column] = ImportedHtml::clean($data[$column]);
            }
        }
        foreach (['name', 'subject', 'title', 'label', 'help_text', 'field_label', 'value'] as $column) {
            if (isset($data[$column])) {
                $data[$column] = strip_tags($data[$column]);
            }
        }
        foreach (['created_at', 'updated_at'] as $column) {
            if (in_array($column, self::FIELDS[$table], true)) {
                $data[$column] ??= now();
            }
        }
        if ($table === 'support__categories') {
            $data['permission'] = 'imports.review';
            $data['enabled'] = false;
            $data['auto_close_hours'] = null;
            $data['access'] = in_array($row['access'] ?? '', ['members', 'roles'], true) ? $row['access'] : 'members';
        }
        if ($table === 'support__tickets') {
            $data['status'] = in_array($row['status'] ?? '', ['open', 'answered', 'closed'], true)
                ? $row['status']
                : 'closed';
            $data['priority'] = in_array($row['priority'] ?? '', ['low', 'normal', 'high', 'urgent'], true)
                ? $row['priority']
                : 'normal';
            $data += ['first_response_due_at' => null, 'resolution_due_at' => null, 'escalated_at' => null];
        }
        if ($table === 'support__messages') {
            $data['internal'] = (bool) ($row['internal'] ?? false);
        }
        if ($table === 'support__templates') {
            $data['enabled'] = false;
            $data['kind'] = in_array($row['kind'] ?? '', ['reply', 'introduction'], true) ? $row['kind'] : 'reply';
        }
        if ($table === 'support__fields') {
            $data['type'] = in_array($row['type'] ?? '', Field::TYPES, true)
                ? $row['type']
                : 'text';
            $options = isset($row['options']) ? json_decode($row['options'], true, 16, JSON_THROW_ON_ERROR) : null;
            $data['options'] = is_array($options)
                ? json_encode(
                    array_values(array_map(strip_tags(...), array_filter($options, is_string(...)))),
                    JSON_THROW_ON_ERROR,
                )
                : null;
        }
        if ($table === 'support__ticket_events') {
            $data['data'] = null;
        }

        return ['target_table' => $table, 'target_id' => $context->insert($table, $data)];
    }
}
