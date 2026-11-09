<?php

namespace KraftInk\Plugins\Support\Listeners;

use App\Events\PruningData;
use App\Kraft\System\RevisionRetention;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use KraftInk\Plugins\Support\Models\MessageRevision;
use KraftInk\Plugins\Support\Services\SupportDrafts;
use KraftInk\Plugins\Support\Services\SupportFiles;

class SupportDataRetention
{
    public function prune(PruningData $event): void
    {
        $event->add(
            'support.message_revisions',
            app(RevisionRetention::class)->prune(
                MessageRevision::query(),
                'message_id',
                $event->dryRun,
                fn (Builder|EloquentBuilder $query) => $query->whereExists(
                    fn (Builder $tickets) => $tickets
                        ->selectRaw('1')
                        ->from('support__messages')
                        ->join('support__tickets', 'support__tickets.id', '=', 'support__messages.ticket_id')
                        ->whereColumn('support__messages.id', 'support__message_revisions.message_id')
                        ->where('support__tickets.status', 'closed'),
                ),
            ),
        );
        $event->add('support.drafts', app(SupportDrafts::class)->cleanup($event->dryRun));
        $event->add('support.files', app(SupportFiles::class)->cleanup(dryRun: $event->dryRun));
    }
}
