@use('Illuminate\Pagination\LengthAwarePaginator')
@use('KraftInk\Plugins\Support\Models\MessageRevision')
@use('App\Kraft\SiteDates')
@php
    /** @var LengthAwarePaginator<int, MessageRevision> $revisions */
@endphp
@extends('default.layouts.layout')
@section('title', __('support::messages.history.title'))
@section('content')
    <section class="pb-4">
        <header class="page-heading">
            <a class="small text-decoration-none" href="{{ route('plugins.support.public.tickets.show', $ticket->id) }}"><i
                    class="bi bi-arrow-left me-1" aria-hidden="true"></i>{{ $ticket->subject }}</a>
            <h1 class="h2 mt-3 mb-0">{{ __('support::messages.history.title') }}</h1>
        </header>
        @if (!$message->deleted_at)
            <form data-ajax="true" data-redirect-url="response" method="post"
                action="{{ route('plugins.support.public.messages.update', $message->id) }}"
                class="card card-body site-panel p-4 mb-4">
                @csrf
                @method('PUT')
                <label class="form-label" for="support-edit-body">{{ __('support::messages.history.edit') }}</label>
                <textarea class="form-control mb-3" id="support-edit-body" name="body" rows="8" data-editor="tinymce"
                    data-editor-upload-url="{{ route('plugins.support.public.images.store', ['ticket' => $ticket->id]) }}" required>{{ old('body', $message->body) }}</textarea>
                <div class="border-top pt-3 mt-3 text-end">
                    <button class="btn btn-primary">{{ __('support::messages.tickets.save') }}</button>
                </div>
            </form>
        @else
            <p class="alert alert-info">{{ __('support::messages.history.removed') }}</p>
        @endif
        <h2 class="h5 section-heading">{{ __('support::messages.history.revisions') }}</h2>
        <div id="support-message-revisions" data-ajax-pagination tabindex="-1" class="vstack gap-4">
            @foreach ($revisions as $revision)
                <article class="card card-body site-panel p-4 mb-3">
                    <p class="small text-body-secondary">{{ app(SiteDates::class)->format($revision->created_at) }}
                        @if ($revision->deleted_at)
                            · {{ __('support::messages.history.removed') }}
                        @endif
                    </p>
                    @if ($revision->anonymized)
                        <p>{{ __('support::messages.history.unavailable') }}</p>
                    @else
                        <div class="prose mb-3">
                            {!! $revision->body !!}
                        </div>
                        @if (!$message->deleted_at && $revision->body !== '')
                            <form data-ajax="true" data-redirect-url="response" method="post"
                                action="{{ route('plugins.support.public.messages.revisions.restore', [$message->id, $revision->id]) }}">
                                @csrf
                                <button class="btn btn-sm btn-outline-primary">{{ __('support::messages.history.restore') }}</button>
                            </form>
                        @endif
                    @endif
                </article>
            @endforeach
            {{ $revisions->links() }}
        </div>
    </section>
@endsection
