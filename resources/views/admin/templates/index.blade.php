@use('Illuminate\Pagination\LengthAwarePaginator')
@use('KraftInk\Plugins\Support\Models\ReplyTemplate')
@use('Illuminate\Support\Collection')
@use('KraftInk\Plugins\Support\Models\Category')
@php
    /** @var LengthAwarePaginator<int, ReplyTemplate> $templates */
    /** @var Collection<int, Category> $categories */
@endphp
@extends('admin.layouts.layout')
@section('title', __('support::admin.templates.templates'))
@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#support-template-new">
        {{ __('support::admin.templates.new_template') }}</button>
@endsection
@section('content')
    <div id="support-admin-templates" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <div class="card">
            <x-tables.controls :paginator="$templates" container-class="card-body" />
            <div class="card-body p-0 table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th scope="col" data-table-sort="title">{{ __('support::admin.templates.template_title') }}</th>
                            <th scope="col" data-table-sort="kind">{{ __('support::admin.templates.template_kind') }}</th>
                            <th scope="col" data-table-sort="locale">{{ __('support::messages.tickets.language') }}</th>
                            <th scope="col" data-table-sort="enabled">{{ __('support::admin.notifications.state') }}</th>
                            <th>{{ __('support::messages.search.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($templates as $template)
                            <tr>
                                <td>{{ $template->title }}</td>
                                <td>{{ __('support::admin.templates.kind_' . $template->kind) }}</td>
                                <td>{{ strtoupper($template->locale) }}</td>
                                <td>{{ __('support::admin.categories.' . ($template->enabled ? 'enabled' : 'disabled')) }}</td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                            data-bs-target="#support-template-{{ $template->id }}">{{ __('support::admin.settings.edit') }}</button>
                                        <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('plugins.support.admin.templates.destroy', $template->id) }}"
                                            data-confirm="{{ __('support::admin.settings.confirm_delete') }}">
                                            @csrf
                                            @method('DELETE')
                                            <button
                                                class="btn btn-sm btn-outline-danger">{{ __('support::messages.tickets.delete') }}</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <x-tables.empty :colspan="5" :message="__('support::messages.tickets.empty')" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $templates->links() }}
            </div>
        </div>
        @foreach ($templates->concat([null]) as $template)
            @php
                $modalId = 'support-template-' . ($template?->id ?? 'new');
            @endphp
            <x-forms.modal :id="$modalId" :title="$template?->title ?? __('support::admin.templates.new_template')" :action="$template ? route('plugins.support.admin.templates.update', $template->id) : route('plugins.support.admin.templates.store')" :method="$template ? 'PUT' : 'POST'">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label" for="{{ $modalId }}-title-input">{{ __('support::admin.templates.template_title') }}</label>
                        <input class="form-control" id="{{ $modalId }}-title-input" name="title" value="{{ $template?->title }}" required maxlength="120">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="{{ $modalId }}-locale">{{ __('support::messages.tickets.language') }}</label>
                        <select class="form-select" id="{{ $modalId }}-locale" name="locale">
                            @foreach (array_keys(config('kraft.locales')) as $locale)
                                <option @selected(($template?->locale ?? app()->getLocale()) === $locale) value="{{ $locale }}">{{ strtoupper($locale) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="{{ $modalId }}-kind">{{ __('support::admin.templates.template_kind') }}</label>
                        <select class="form-select" id="{{ $modalId }}-kind" name="kind">
                            @foreach (['reply', 'opening'] as $kind)
                                <option value="{{ $kind }}" @selected(($template?->kind ?? 'reply') === $kind)>
                                    {{ __('support::admin.templates.kind_' . $kind) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="{{ $modalId }}-category">{{ __('support::messages.tickets.category') }}</label>
                        <select class="form-select" id="{{ $modalId }}-category" name="category_id">
                            <option value="">{{ __('support::messages.search.all_categories') }}</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected($template?->category_id === $category->id)>{{ $category->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="{{ $modalId }}-body">{{ __('support::messages.tickets.message') }}</label>
                        <textarea class="form-control" id="{{ $modalId }}-body" name="body" rows="8" data-editor="tinymce" maxlength="30000" required>{{ $template?->body }}</textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="{{ $modalId }}-position">{{ __('support::messages.tickets.position') }}</label>
                        <input class="form-control" id="{{ $modalId }}-position" type="number" name="position"
                            value="{{ $template?->position ?? 0 }}" min="0" max="10000" required>
                    </div>
                    <div class="col-md-8 align-self-end">
                        <input type="hidden" name="enabled" value="0">
                        <label class="form-check">
                            <input class="form-check-input" name="enabled" type="checkbox" value="1"
                                @checked($template?->enabled ?? true)>
                            <span class="form-check-label">{{ __('support::admin.categories.enabled') }}</span></label>
                    </div>
                </div>
            </x-forms.modal>
        @endforeach
    </div>
@endsection
