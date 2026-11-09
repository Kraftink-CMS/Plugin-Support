@use('Illuminate\Support\Collection')
@use('KraftInk\Plugins\Support\Models\FieldValue')
@use('App\Models\Accounts\User')
@use('KraftInk\Plugins\Support\Models\TicketEvent')
@use('Illuminate\Pagination\LengthAwarePaginator')
@use('KraftInk\Plugins\Support\Models\Message')
@use('App\Kraft\SiteDates')
@php
    /** @var Collection<int, FieldValue> $fieldValues */
    /** @var Collection<int, User> $agents */
    /** @var Collection<int, TicketEvent> $events */
    /** @var LengthAwarePaginator<int, Message> $messages */
    $backRoute = request()->routeIs('plugins.support.admin.tickets.show') || !auth()->user()->hasPermission('support.view')
        ? 'plugins.support.admin.tickets.index'
        : 'plugins.support.public.tickets.index';
@endphp
@include('support::partials.assets')
<section class="py-4">
    <a href="{{ route($backRoute) }}">{{ __('support::messages.tickets.tickets') }}</a>
    <h1 class="h2">#{{ $ticket->id }} · {{ $ticket->subject }}</h1>
    <p>{{ __('support::messages.tickets.' . $ticket->status) }}</p>
    @if ($fieldValues->isNotEmpty())
        <section class="card card-body mb-4">
            <h2>{{ __('support::admin.forms.provided_details') }}</h2>
            <dl>
                @foreach ($fieldValues as $value)
                    <dt>{{ $value->field_label }}
                        @if ($value->staff_only)
                            <span class="badge text-bg-secondary">{{ __('support::messages.tickets.internal') }}</span>
                        @endif
                    </dt>
                    <dd class="text-break">{{ $value->value }}</dd>
                @endforeach
            </dl>
        </section>
    @endif
    @if ($staff)
        <section class="card mb-4">
            <div class="card-header">
                <h2 class="h5 mb-0">{{ __('support::admin.forms.assignment') }}</h2>
            </div>
            <div class="card-body">
                <p class="mb-0">{{ $assignedAgentUsername ?? __('support::admin.forms.unassigned') }}</p>
            </div>
            @if ($canAssign)
                <form data-ajax="true" data-redirect-url="response" method="post"
                    action="{{ route('plugins.support.public.tickets.assign', $ticket->id) }}">
                    @csrf
                    @method('PATCH')
                    <div class="card-body">
                        <label class="form-label d-block mb-2"
                            for="assigned_to">{{ __('support::admin.forms.assign_to') }}</label>
                        <select class="form-select" id="assigned_to" name="assigned_to">
                            <option value="">{{ __('support::admin.forms.unassigned') }}</option>
                            @foreach ($agents as $agent)
                                <option value="{{ $agent->id }}" @selected($ticket->assigned_to === $agent->id)>{{ $agent->username }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="card-footer text-end">
                        <button class="btn btn-primary"
                            type="submit">{{ __('support::messages.tickets.save') }}</button>
                    </div>
                </form>
            @endif
        </section>
        @if ($fields->isNotEmpty())
            <details class="card mb-4" @if ($errors->has('custom_fields.*')) open @endif>
                <summary class="card-header">{{ __('support::admin.forms.update_details') }}</summary>
                <form data-ajax="true" data-redirect-url="response" method="post"
                    action="{{ route('plugins.support.public.tickets.fields.update', $ticket->id) }}">
                    @csrf
                    @method('PUT')
                    <div class="card-body">
                        @include('support::partials.tickets.fields')
                    </div>
                    <div class="card-footer text-end">
                        <button class="btn btn-primary"
                            type="submit">{{ __('support::messages.tickets.save') }}</button>
                    </div>
                </form>
            </details>
        @endif
        <details class="card mb-4">
            <summary class="card-header">{{ __('support::admin.forms.history') }}</summary>
            <div class="card-body">
                @foreach ($events as $event)
                    <p><strong>{{ __($event->getAttribute('labelKey')) }}</strong>
                        · {{ $event->getAttribute('actor_username') ?? __('support::messages.tickets.deleted_user') }} ·
                        {{ app(SiteDates::class)->format($event->created_at) }}</p>
                @endforeach
            </div>
        </details>
    @endif
    @if ($staff)
        <div class="card card-body mb-4">
            <dl class="row mb-0">
                <dt class="col-sm-5">{{ __('support::admin.deadlines.first_reply') }}</dt>
                <dd class="col-sm-7">
                    {{ $ticket->first_staff_reply_at ? app(SiteDates::class)->format($ticket->first_staff_reply_at) : __('support::admin.deadlines.not_replied') }}
                </dd>
                @if ($ticket->first_response_due_at)
                    <dt class="col-sm-5">{{ __('support::admin.deadlines.first_due') }}</dt>
                    <dd class="col-sm-7">{{ app(SiteDates::class)->format($ticket->first_response_due_at) }}
                    </dd>
                @endif
                @if ($ticket->resolution_due_at)
                    <dt class="col-sm-5">{{ __('support::admin.deadlines.resolution_due') }}</dt>
                    <dd class="col-sm-7">{{ app(SiteDates::class)->format($ticket->resolution_due_at) }}
                    </dd>
                @endif
            </dl>
            @if ($ticket->escalated_at)
                <p class="text-warning mb-0">{{ __('support::admin.deadlines.escalated') }}</p>
            @endif
        </div>
    @endif
    <div id="support-ticket-messages" data-ajax-pagination tabindex="-1" class="vstack gap-4">
        @foreach ($messages->reverse() as $message)
            <article class="card card-body mb-4" id="message-{{ $message->id }}">
                <header class="mb-3">
                    <strong>{{ $message->getAttribute('username') ?? __('support::messages.tickets.deleted_user') }}</strong>
                    ·
                    {{ app(SiteDates::class)->format($message->created_at) }}
                    @if ($message->internal)
                        <span class="badge text-bg-secondary">{{ __('support::messages.tickets.internal') }}</span>
                    @endif
                </header>
                <div class="prose">
                    @if ($message->deleted_at)
                        <p>{{ __('support::messages.history.removed') }}</p>
                    @elseif ($message->id === $firstMessageId && $message->body === '')
                        <p class="text-body-secondary">{{ __('support::admin.forms.event_created') }}</p>
                    @else
                        {!! $message->body !!}
                    @endif
                </div>
                @if ($message->getAttribute('canDownloadAttachment'))
                    <a
                        href="{{ route('plugins.support.public.attachments.download', $message->id) }}">{{ $message->attachment->name }}</a>
                @endif
                @if ($message->getAttribute('canManage'))
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <a class="btn btn-sm btn-outline-secondary"
                            href="{{ route('plugins.support.public.messages.history', $message->id) }}">{{ __('support::messages.history.title') }}</a>
                        @if ($message->getAttribute('canDelete'))
                            <form data-ajax="true" data-redirect-url="response" method="post"
                                action="{{ route('plugins.support.public.messages.destroy', $message->id) }}"
                                data-confirm="{{ __('support::messages.history.confirm_delete') }}">
                                @csrf
                                @method('DELETE')
                                <input type="hidden" name="confirmed" value="1">
                                <button
                                    class="btn btn-sm btn-outline-danger">{{ __('support::messages.history.delete') }}</button>
                            </form>
                        @endif
                    </div>
                @endif
            </article>
        @endforeach
        {{ $messages->links() }}
    </div>
    @if ($canReply)
        <form data-ajax="true" data-redirect-url="response" class="card mb-4" method="post"
            enctype="multipart/form-data" action="{{ route('plugins.support.public.tickets.reply', $ticket->id) }}"
            data-support-draft="ticket_{{ $ticket->id }}"
            data-support-draft-url="{{ route('plugins.support.public.drafts.store') }}"
            data-support-discard-url="{{ route('plugins.support.public.drafts.destroy') }}"
            data-saved="{{ __('support::messages.drafts.draft_saved') }}"
            data-discarded="{{ __('support::messages.drafts.discarded') }}"
            data-failed="{{ __('support::messages.drafts.draft_failed') }}">
            @csrf
            <div class="card-body">
                @if ($draft)
                    <p class="alert alert-info">{{ __('support::messages.drafts.draft_restored') }}</p>
                @endif
                @include('support::partials.tickets.template-picker')
                <label class="form-label" for="body">{{ __('support::messages.tickets.reply') }}</label>
                <textarea class="form-control mb-3" id="body" name="body" rows="8" data-editor="tinymce"
                    data-editor-upload-url="{{ route('plugins.support.public.images.store', ['ticket' => $ticket->id]) }}" required>{{ old('body', $draft['body'] ?? '') }}</textarea>
                <label class="form-label" for="attachment">{{ __('support::messages.tickets.attachment') }}</label>
                <input class="form-control mb-3" id="attachment" name="attachment" type="file"
                    accept=".png,.jpg,.jpeg,.webp,.pdf,.txt,.log">
                @if ($staff)
                    <label class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="internal" value="1"
                            @checked(old('internal', $draft['internal'] ?? false))>
                        <span class="form-check-label">{{ __('support::messages.tickets.internal') }}</span></label>
                @endif
                <p class="small" aria-live="polite" data-support-draft-status>
                </p>
                <details class="small mb-3" data-support-draft-discard
                    @if (!$draft) hidden @endif>
                    <summary>{{ __('support::messages.drafts.discard') }}</summary>
                    <p class="my-2">{{ __('support::messages.drafts.discard_confirm') }}</p>
                    <button class="btn btn-sm btn-outline-danger" type="button"
                        data-support-draft-discard-button>{{ __('support::messages.drafts.discard') }}</button>
                </details>
            </div>
            <div class="card-footer text-end">
                <button class="btn btn-primary">{{ __('support::messages.tickets.send') }}</button>
            </div>
        </form>
    @endif
    @if (count($ticketStatuses) > 1)
        <form data-ajax="true" data-redirect-url="response" method="post"
            action="{{ route('plugins.support.public.tickets.update', $ticket->id) }}" class="card">
            @csrf
            @method('PATCH')
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="status">{{ __('support::messages.tickets.status') }}</label>
                        <select class="form-select" id="status" name="status">
                            @foreach ($ticketStatuses as $status)
                                <option value="{{ $status }}" @selected(old('status', $ticket->status) === $status)>
                                    {{ __('support::messages.tickets.' . $status) }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($staff)
                        <div class="col-md-6">
                            <label class="form-label"
                                for="priority">{{ __('support::messages.tickets.priority') }}</label>
                            <select class="form-select" id="priority" name="priority">
                                @foreach (['low', 'normal', 'high', 'urgent'] as $priority)
                                    <option value="{{ $priority }}" @selected(old('priority', $ticket->priority) === $priority)>
                                        {{ __('support::messages.tickets.' . $priority) }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                </div>
            </div>
            <div class="card-footer text-end">
                <button class="btn btn-primary">{{ __('support::messages.tickets.save') }}</button>
            </div>
        </form>
    @endif
</section>
