<?php

namespace KraftInk\Plugins\Support\Providers;

use App\Events\PruningData;
use App\Kraft\Auth\ApiScopes;
use App\Kraft\Extensions\SlotRegistry;
use App\Kraft\Features;
use App\Kraft\Widgets\WidgetRegistry;
use App\Models\Accounts\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use KraftInk\Plugins\Support\Console\Commands\ProcessSupport;
use KraftInk\Plugins\Support\Listeners\SupportDataRetention;
use KraftInk\Plugins\Support\Models\Ticket;
use KraftInk\Plugins\Support\Services\Imports\ImportMappings;
use KraftInk\Plugins\Support\Services\SupportAccess;
use KraftInk\Plugins\Support\Services\SupportNotifications;
use KraftInk\Plugins\Support\Services\SupportTrash;
use App\Kraft\Events\PluginEvents;
use App\Kraft\Migration\MappingRegistry;
use App\Kraft\Notifications\NotificationRegistry;
use App\Kraft\Trash\TrashRegistry;

class PluginServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $events = app(PluginEvents::class);
        $events->define('support.ticket.created');
        $events->define('support.ticket.replied');
        $events->define('support.ticket.resolved');
        SupportNotifications::register(app(NotificationRegistry::class));
        app(ApiScopes::class)->register(
            'support',
            'support:tickets:read',
            'support::api.scope_label',
            'support::api.scope_description',
            ['support.view'],
        );
        $this->registerWidgets(app(WidgetRegistry::class));
        $slots = app(SlotRegistry::class);
        $slots->add(
            owner: 'support',
            slot: 'admin.dashboard.metrics',
            id: 'open-tickets',
            render: fn () => view('support::admin.dashboard.open-tickets', [
                'count' => Ticket::query()->whereNull('deleted_at')->where('status', '!=', 'closed')->count(),
            ]),
            permission: 'support.manage',
        );
        $slots->add(
            owner: 'support',
            slot: 'admin.users.edit.actions',
            id: 'member-tickets',
            render: 'support::admin.users.actions',
            permission: 'support.manage',
        );
        app(Features::class)->register('support', fn (?User $user) => app(SupportAccess::class)->available($user));
        Event::listen(PruningData::class, [SupportDataRetention::class, 'prune']);
        $this->commands([ProcessSupport::class]);
        $this->app->afterResolving(
            Schedule::class,
            fn ($schedule) => $schedule->command('support:process')->everyMinute()->withoutOverlapping(5),
        );
        app(ImportMappings::class)->register(app(MappingRegistry::class));
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'support');
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'support');
        app(SupportTrash::class)->register(app(TrashRegistry::class));
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../../routes/admin.php');
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
    }

    private function registerWidgets(WidgetRegistry $widgets): void
    {
        $widgets->register(
            owner: 'support',
            id: 'my-tickets',
            label: 'support::widgets.my_tickets',
            render: function (array $context, array $config) {
                $user = $context['user'] ?? null;
                if (!$user || !app(SupportAccess::class)->available($user)) {
                    return '';
                }

                return view('support::widgets.my-tickets', [
                    'tickets' => Ticket::query()
                        ->where('user_id', $user->id)
                        ->whereNull('deleted_at')
                        ->orderByDesc('updated_at')
                        ->orderByDesc('id')
                        ->limit($config['limit'])
                        ->get(['id', 'subject']),
                ]);
            },
            fields: ['limit' => ['type' => 'integer', 'label' => 'support::widgets.count', 'min' => 1, 'max' => 12]],
            permission: 'support.view',
            zones: ['home'],
            defaults: ['limit' => 5],
        );
    }
}
