@use('Illuminate\Pagination\LengthAwarePaginator')
@use('KraftInk\Plugins\Support\Models\Ticket')
@use('App\Kraft\Settings')
@php
    /** @var LengthAwarePaginator<int, Ticket> $tickets */
@endphp
@extends('default.layouts.layout')
@section('title', __('support::messages.tickets.title'))
@section('content')
    @include('support::partials.assets')
    <section class="pb-4">
        <header class="page-heading">
            <h1 class="h2 mb-2">{{ __('support::messages.tickets.title') }}</h1>
            @if ($introduction = app(Settings::class)->get('support.introduction'))
                <div class="prose text-body-secondary">
                    {!! $introduction !!}
                </div>
            @endif
        </header>
        <form class="card card-body site-panel mb-4" method="get" data-ajax-pagination-form="support-public-tickets">
            <div class="row g-3 align-items-start">
                <div class="col-md-6">
                    <label class="form-label" for="support-search">{{ __('support::messages.search.search') }}</label>
                    <input class="form-control" id="support-search" name="q" maxlength="200"
                        value="{{ request('q') }}">
                    <div class="form-text">
                        {{ __('support::messages.search.search_help') }}
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="support-status">{{ __('support::messages.tickets.status') }}</label>
                    <select class="form-select" id="support-status" name="status">
                        <option value="">{{ __('support::messages.search.all') }}</option>
                        @foreach (['open', 'answered', 'closed'] as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>
                                {{ __('support::messages.tickets.' . $status) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 d-grid align-self-center">
                    <button class="btn btn-outline-primary"><i class="bi bi-funnel me-2"
                            aria-hidden="true"></i>{{ __('support::messages.search.filter') }}</button>
                </div>
            </div>
        </form>
        <div class="row g-4">
            <div id="support-public-tickets" data-ajax-pagination tabindex="-1" @class([
                'col-lg-6' => $categories->isNotEmpty(),
                'col-12' => $categories->isEmpty(),
            ])>
                <h2 class="h5 section-heading">{{ __('support::messages.tickets.tickets') }}</h2>
                <div class="list-group mb-4">
                    @forelse($tickets as $ticket)
                        <article class="list-group-item p-3 p-md-4">
                            <div class="d-flex justify-content-between align-items-start gap-3">
                                <div>
                                    <small class="text-body-secondary d-block mb-1">#{{ $ticket->id }}</small>
                                    <a class="fw-medium text-decoration-none"
                                        href="{{ route('plugins.support.public.tickets.show', $ticket->id) }}">{{ $ticket->subject }}</a>
                                </div>
                                <span
                                    @class([
                                        'badge',
                                        'text-bg-primary' => $ticket->status === 'open',
                                        'text-bg-success' => $ticket->status === 'answered',
                                        'text-bg-secondary' => $ticket->status === 'closed',
                                    ])>{{ __('support::messages.tickets.' . $ticket->status) }}</span>
                            </div>
                        </article>
                    @empty
                        <div class="list-group-item empty-state text-center">
                            <i class="bi bi-chat-left-text fs-2 d-block mb-3" aria-hidden="true"></i>
                            <p class="mb-0">{{ __('support::messages.tickets.empty') }}</p>
                        </div>
                    @endforelse
                </div>
                {{ $tickets->links() }}
            </div>
            @if ($categories->isNotEmpty())
                <div class="col-lg-6">
                    <form class="card card-body site-panel mb-4" method="get"
                        action="{{ route('plugins.support.public.tickets.index') }}">
                        <label class="form-label"
                            for="support-category">{{ __('support::messages.tickets.category') }}</label>
                        <div class="input-group">
                            <select class="form-select" id="support-category" name="category">
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" @selected($selectedCategory?->id === $category->id)>{{ $category->name }}
                                    </option>
                                @endforeach
                            </select>
                            <button
                                class="btn btn-outline-secondary">{{ __('support::admin.forms.choose_category') }}</button>
                        </div>
                    </form>
                    @if ($selectedCategory)
                        <form data-ajax="true" data-redirect-url="response" class="card site-panel" method="post"
                            enctype="multipart/form-data" action="{{ route('plugins.support.public.tickets.store') }}"
                            data-support-draft="category_{{ $selectedCategory->id }}"
                            data-support-draft-url="{{ route('plugins.support.public.drafts.store') }}"
                            data-support-discard-url="{{ route('plugins.support.public.drafts.destroy') }}"
                            data-saved="{{ __('support::messages.drafts.draft_saved') }}"
                            data-discarded="{{ __('support::messages.drafts.discarded') }}"
                            data-failed="{{ __('support::messages.drafts.draft_failed') }}">
                            @csrf
                            <div class="card-header py-3">
                                <h2 class="h5 mb-0">
                                    {{ __('support::messages.tickets.create') }} · {{ $selectedCategory->name }}</h2>
                            </div>
                            <div class="card-body p-4">
                                @if ($selectedCategory->introduction)
                                    <div class="prose mb-3">
                                        {!! $selectedCategory->introduction !!}
                                    </div>
                                @endif
                                <input type="hidden" name="category_id" value="{{ $selectedCategory->id }}">
                                @if ($draft)
                                    <p class="alert alert-info">{{ __('support::messages.drafts.draft_restored') }}</p>
                                @endif
                                <div class="mb-3">
                                    <label class="form-label"
                                        for="subject">{{ __('support::messages.tickets.subject') }}</label>
                                    <input class="form-control" id="subject" name="subject"
                                        value="{{ old('subject', $draft['subject'] ?? '') }}" maxlength="180" required>
                                </div>
                                @include('support::partials.tickets.fields')
                                @unless ($selectedCategory->structured_form_only)
                                    @include('support::partials.tickets.template-picker')
                                    <div class="mb-3">
                                        <label class="form-label"
                                            for="body">{{ __('support::messages.tickets.message') }}</label>
                                        <textarea class="form-control" id="body" name="body" rows="8" data-editor="tinymce"
                                            data-editor-upload-url="{{ route('plugins.support.public.images.store', ['category' => $selectedCategory->id]) }}"
                                            required>{{ old('body', $draft['body'] ?? '') }}</textarea>
                                    </div>
                                @endunless
                                <div class="mb-3">
                                    <label class="form-label"
                                        for="attachment">{{ __('support::messages.tickets.attachment') }}</label>
                                    <input class="form-control" type="file" name="attachment" id="attachment"
                                        accept=".png,.jpg,.jpeg,.webp,.pdf,.txt,.log">
                                </div>
                                <p class="form-text">{{ __('support::messages.drafts.draft_privacy') }}</p>
                                <p class="small" aria-live="polite" data-support-draft-status></p>
                                <details class="small" data-support-draft-discard
                                    @if (!$draft) hidden @endif>
                                    <summary>{{ __('support::messages.drafts.discard') }}</summary>
                                    <p class="my-2">{{ __('support::messages.drafts.discard_confirm') }}</p>
                                    <button class="btn btn-sm btn-outline-danger" type="button"
                                        data-support-draft-discard-button>{{ __('support::messages.drafts.discard') }}</button>
                                </details>
                            </div>
                            <div class="card-footer px-4 py-3 text-end">
                                <button class="btn btn-primary"><i class="bi bi-send me-2"
                                        aria-hidden="true"></i>{{ __('support::messages.tickets.send') }}</button>
                            </div>
                        </form>
                    @endif
                </div>
            @endif
        </div>
    </section>
@endsection
