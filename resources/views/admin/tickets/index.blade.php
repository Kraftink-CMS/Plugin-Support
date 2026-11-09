@use('Illuminate\Support\Collection')
@use('KraftInk\Plugins\Support\Models\Category')
@use('App\Models\Accounts\User')
@use('Illuminate\Pagination\LengthAwarePaginator')
@use('KraftInk\Plugins\Support\Models\Ticket')
@use('App\Kraft\SiteDates')
@php
    /** @var Collection<int, Category> $categories */
    /** @var Collection<int, User> $agents */
    /** @var LengthAwarePaginator<int, Ticket> $tickets */
@endphp
@extends('admin.layouts.layout')
@section('title', __('support::messages.tickets.tickets'))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('plugins.support.public.tickets.index') }}" target="_blank">
        {{ __('support::messages.tickets.create') }}</a>
@endsection
@section('content')
    <div id="support-admin-tickets" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <div class="d-flex flex-wrap gap-3">
            @foreach (['open', 'answered', 'closed'] as $status)
                <a class="text-decoration-none"
                    href="{{ route('plugins.support.admin.tickets.index', ['status' => $status, 'user_id' => $authorId]) }}">{{ __('support::messages.tickets.' . $status) }} <span
                        class="badge text-bg-secondary">{{ $counts[$status] ?? 0 }}</span></a>
            @endforeach
        </div>
        <div class="card">
            <div class="card-body">
                <x-tables.controls :paginator="$tickets" class="mb-3" />
                @if ($authorId !== null)
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <span>{{ __('support::messages.search.author') }}</span>
                        <a class="badge rounded-pill text-bg-secondary text-decoration-none" href="{{ request()->fullUrlWithoutQuery(['user_id', 'page']) }}"
                            aria-label="{{ __('support::admin.tickets.clear_author', ['name' => $authorName ?? '#' . $authorId]) }}">
                            {{ $authorName ?? '#' . $authorId }} <i class="bi bi-x-lg ms-1" aria-hidden="true"></i>
                        </a>
                    </div>
                @endif
                <form method="get">
                    @if ($authorId !== null)
                        <input type="hidden" name="user_id" value="{{ $authorId }}">
                    @endif
                    <div class="row g-3 align-items-end">
                        <div class="col-lg-4">
                            <label class="form-label" for="support-search">{{ __('support::messages.search.search') }}</label>
                            <input class="form-control" id="support-search" name="q" value="{{ request('q') }}" maxlength="200">
                        </div>
                        <div class="col-lg-2">
                            <label class="form-label" for="status">{{ __('support::messages.tickets.status') }}</label>
                            <select class="form-select" id="status" name="status">
                                <option value="">{{ __('support::messages.search.all') }}</option>
                                <option value="active" @selected(request('status') === 'active')>{{ __('support::admin.tickets.active') }}</option>
                                @foreach (['open', 'answered', 'closed'] as $status)
                                    <option value="{{ $status }}" @selected(request('status') === $status)>
                                        {{ __('support::messages.tickets.' . $status) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label" for="category_id">{{ __('support::messages.tickets.category') }}</label>
                            <select class="form-select" id="category_id" name="category_id">
                                <option value="">{{ __('support::messages.search.all') }}</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" @selected((int) request('category_id') === $category->id)>{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-lg-3">
                            <label class="form-label" for="assigned_to">{{ __('support::admin.forms.assignment') }}</label>
                            <select class="form-select" id="assigned_to" name="assigned_to">
                                <option value="">{{ __('support::messages.search.all') }}</option>
                                <option value="unassigned" @selected(request('assigned_to') === 'unassigned')>{{ __('support::admin.tickets.unassigned') }}</option>
                                @foreach ($agents as $agent)
                                    <option value="{{ $agent->id }}" @selected((int) request('assigned_to') === $agent->id)>{{ $agent->username }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-auto">
                            <label class="form-check">
                                <input class="form-check-input" name="overdue" type="checkbox" value="1"
                                    @checked(request()->boolean('overdue'))>
                                <span class="form-check-label">{{ __('support::messages.search.overdue') }}</span></label>
                        </div>
                        <noscript>
                            <div class="col-auto">
                                <button class="btn btn-primary">{{ __('support::messages.search.filter') }}</button>
                            </div>
                        </noscript>
                    </div>
                </form>
            </div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th scope="col" data-table-sort="subject">{{ __('support::messages.tickets.subject') }}</th>
                            <th scope="col" data-table-sort="author">{{ __('support::messages.search.author') }}</th>
                            <th scope="col" data-table-sort="category">{{ __('support::messages.tickets.category') }}</th>
                            <th scope="col" data-table-sort="status">{{ __('support::messages.tickets.status') }}</th>
                            <th scope="col" data-table-sort="updated_at">{{ __('support::messages.search.updated') }}</th>
                            <th>{{ __('support::messages.search.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($tickets as $ticket)
                            <tr>
                                <td>#{{ $ticket->id }} · {{ $ticket->subject }}
                                    @if ($ticket->escalated_at && $ticket->status !== 'closed')
                                        <span class="badge text-bg-warning">{{ __('support::messages.search.overdue') }}</span>
                                    @endif
                                </td>
                                <td>{{ $ticket->getAttribute('author_username') ?? __('support::messages.tickets.deleted_user') }}</td>
                                <td>{{ $ticket->getAttribute('category') }}</td>
                                <td>{{ __('support::messages.tickets.' . $ticket->status) }}<small
                                        class="d-block text-body-secondary">{{ __('support::messages.tickets.' . $ticket->priority) }}</small>
                                </td>
                                <td>{{ app(SiteDates::class)->format($ticket->updated_at) }}</td>
                                <td>
                                    <div class="d-flex flex-wrap gap-2">
                                        <a class="btn btn-sm btn-outline-primary"
                                            href="{{ route('plugins.support.admin.tickets.show', $ticket->id) }}">{{ __('support::messages.search.open_ticket') }}</a>
                                        <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('plugins.support.admin.tickets.destroy', $ticket->id) }}"
                                            data-confirm="{{ __('support::messages.history.confirm_trash') }}">
                                            @csrf
                                            @method('DELETE')
                                            <input type="hidden" name="confirmed" value="1">
                                            <button class="btn btn-sm btn-outline-danger">{{ __('support::messages.history.move_to_trash') }}</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <x-tables.empty :colspan="6" :message="__('support::messages.tickets.empty')" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $tickets->links() }}
            </div>
        </div>
    </div>
@endsection
