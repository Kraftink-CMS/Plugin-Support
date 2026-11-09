<?php

namespace KraftInk\Plugins\Support\Services\Imports\Azuriom;

use App\Kraft\Migration\ImportContext;
use App\Kraft\Migration\ImportedHtml;
use App\Kraft\Migration\MappingRegistry;
use Closure;
use Illuminate\Support\Str;
use RuntimeException;

final class ImportMappings
{
    public function register(MappingRegistry $registry, array $replace, Closure $guard): void
    {
        $registry->register(
            'azuriom',
            'support_categories',
            $this->category(...),
            50,
            'support',
            $replace,
            guard: $guard,
        );
        $registry->register(
            'azuriom',
            'support_tickets',
            $this->ticket(...),
            60,
            'support',
            dependencies: ['users', 'support_categories'],
        );
        $registry->register(
            'azuriom',
            'support_fields',
            $this->field(...),
            55,
            'support',
            dependencies: ['support_categories'],
        );
        $registry->register(
            'azuriom',
            'support_comments',
            $this->message(...),
            70,
            'support',
            dependencies: ['users', 'support_tickets'],
        );
        $registry->settings('azuriom', $this->settings(...), 'support');
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
        $closed = !empty($row['closed_at']);
        $status = $closed ? 'closed' : 'open';

        return [
            'target_table' => 'support__tickets',
            'target_id' => $context->insert('support__tickets', [
                'user_id' => $context->required('users', $row['author_id']),
                'category_id' => $context->required('support_categories', $row['category_id']),
                'subject' => Str::limit(strip_tags($row['subject']), 180, ''),
                'status' => $status,
                'priority' => 'normal',
                'assigned_to' => $context->mapped('users', $row['assignee_id'] ?? null),
                'closed_at' => $closed ? $row['closed_at'] ?? ($row['updated_at'] ?? now()) : null,
                'created_at' => $row['created_at'] ?? now(),
                'updated_at' => $row['updated_at'] ?? now(),
            ]),
        ];
    }

    private function message(array $row, ImportContext $context): array
    {
        return [
            'target_table' => 'support__messages',
            'target_id' => $context->insert('support__messages', [
                'user_id' => $context->required('users', $row['author_id']),
                'ticket_id' => $context->required('support_tickets', $row['ticket_id']),
                'body' => ImportedHtml::clean(
                    Str::markdown($row['content'], ['html_input' => 'strip', 'allow_unsafe_links' => false]),
                ),
                'internal' => (bool) ($row['internal'] ?? false),
                'created_at' => $row['created_at'] ?? now(),
                'updated_at' => $row['updated_at'] ?? ($row['created_at'] ?? now()),
            ]),
        ];
    }

    private function field(array $row, ImportContext $context): array
    {
        $type = match ($row['type']) {
            'dropdown' => 'select',
            'email' => 'text',
            'text', 'textarea', 'number', 'checkbox' => $row['type'],
            default => null,
        };
        if ($type === null) {
            $context->report('unsupported', [
                'plugin' => 'support',
                'table' => 'support_fields',
                'id' => $row['id'],
                'reason' => 'field_type',
            ]);

            return ['metadata' => ['archive_only' => true]];
        }
        $options = isset($row['options']) ? json_decode($row['options'], true, 16) : null;
        if ($type === 'select' && !is_array($options)) {
            throw new RuntimeException('An imported support field has invalid options.');
        }
        if ($row['type'] === 'email' || !empty($row['description'])) {
            $context->report('unsupported', [
                'plugin' => 'support',
                'table' => 'support_fields',
                'id' => $row['id'],
                'reason' => 'field_description_or_email_validation',
            ]);
        }

        return [
            'target_table' => 'support__fields',
            'target_id' => $context->insert('support__fields', [
                'category_id' => $context->required('support_categories', $row['category_id']),
                'key' => Str::limit(Str::snake(Str::ascii($row['name'])) ?: 'field', 40, '').'-'.$row['id'],
                'label' => Str::limit(strip_tags($row['name']), 120, ''),
                'type' => $type,
                'options' => $options === null
                        ? null
                        : json_encode(
                            array_values(
                                array_map(
                                    fn ($value) => strip_tags((string) $value),
                                    array_filter($options, is_scalar(...)),
                                ),
                            ),
                            JSON_THROW_ON_ERROR,
                        ),
                'required' => (bool) ($row['is_required'] ?? false),
                'staff_only' => false,
                'enabled' => true,
                'position' => max(0, (int) ($row['position'] ?? $row['id'])),
            ]),
        ];
    }

    private function settings(array $row, ImportContext $context): void
    {
        if ($row['key'] === 'support.home') {
            $context->setting('support.introduction', ImportedHtml::clean((string) $row['value']));
        }
        if ($row['key'] === 'support.tickets_delay' && is_numeric($row['value'])) {
            $context->setting('support.cooldown_seconds', max(0, min(86400, (int) $row['value'])));
        }
    }
}
