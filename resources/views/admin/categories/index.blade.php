@use('Illuminate\Pagination\LengthAwarePaginator')
@use('KraftInk\Plugins\Support\Models\Category')
@use('Illuminate\Support\Collection')
@use('App\Models\Accounts\Role')
@php
    /** @var LengthAwarePaginator<int, Category> $categories */
    /** @var Collection<int, Role> $roles */
    $hasContentIcons = class_exists(\App\Kraft\ContentIcons::class);
@endphp
@extends('admin.layouts.layout')
@section('title', __('support::admin.categories.categories'))
@section('actions')
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#support-category-new">
        {{ __('support::messages.tickets.new_category') }}</button>
@endsection
@section('content')
    <div id="support-admin-categories" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        <div class="card">
            <x-tables.controls :paginator="$categories" container-class="card-body" />
            <div class="card-body p-0 table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="text-nowrap">
                        <tr>
                            <th scope="col" data-table-sort="name">{{ __('support::messages.tickets.category') }}</th>
                            <th scope="col" data-table-sort="locale">{{ __('support::messages.tickets.language') }}</th>
                            <th scope="col" data-table-sort="access">{{ __('support::admin.categories.access') }}</th>
                            <th scope="col" data-table-sort="enabled">{{ __('support::admin.notifications.state') }}</th>
                            <th>{{ __('support::messages.search.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($categories as $category)
                            <tr>
                                <td>
                                    @if ($hasContentIcons)
                                        <x-dynamic-component component="content-icon" :name="$category->icon" />
                                    @else
                                        <i class="bi bi-{{ $category->icon }}" aria-hidden="true"></i>
                                    @endif
                                    {{ $category->name }}
                                </td>
                                <td>{{ strtoupper($category->locale) }}</td>
                                <td>{{ __('support::admin.categories.access_' . $category->access) }}</td>
                                <td>{{ __('support::admin.deadlines.' . ($category->enabled ? 'enabled' : 'disabled')) }}</td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                            data-bs-target="#support-category-{{ $category->id }}">{{ __('support::admin.settings.edit') }}</button>
                                        <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('plugins.support.admin.categories.destroy', $category->id) }}"
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
                            <x-tables.empty :colspan="5" :message="__('support::admin.categories.no_categories')" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                {{ $categories->links() }}
            </div>
        </div>
        @foreach ($categories->concat([null]) as $category)
            @php
                $modalId = 'support-category-' . ($category?->id ?? 'new');
                $icon = $category?->icon ?? 'life-preserver';
                $icon = old('_modal') === $modalId ? old('icon', $icon) : $icon;
            @endphp
            <x-forms.modal :id="$modalId" :title="$category?->name ?? __('support::messages.tickets.new_category')" :action="$category ? route('plugins.support.admin.categories.update', $category->id) : route('plugins.support.admin.categories.store')" :method="$category ? 'PUT' : 'POST'">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label" for="{{ $modalId }}-name">{{ __('support::messages.tickets.category') }}</label>
                        <input class="form-control" id="{{ $modalId }}-name" name="name" value="{{ $category?->name }}" maxlength="120" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="{{ $modalId }}-locale">{{ __('support::messages.tickets.language') }}</label>
                        <select class="form-select" id="{{ $modalId }}-locale" name="locale">
                            @foreach (array_keys(config('kraft.locales')) as $locale)
                                <option @selected(($category?->locale ?? app()->getLocale()) === $locale) value="{{ $locale }}">{{ strtoupper($locale) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-8">
                        @if ($hasContentIcons)
                            <x-dynamic-component component="admin::icon-picker" :id="$modalId . '-icon'" name="icon"
                                :label="__('support::admin.categories.icon')" :value="$icon" required />
                        @else
                            <label class="form-label" for="{{ $modalId }}-icon">{{ __('support::admin.categories.bootstrap_icon') }}</label>
                            <input class="form-control" id="{{ $modalId }}-icon" name="icon"
                                value="{{ is_string($icon) ? $icon : '' }}" maxlength="60" required>
                        @endif
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="{{ $modalId }}-position">{{ __('support::messages.tickets.position') }}</label>
                        <input class="form-control" id="{{ $modalId }}-position" name="position" type="number" min="0" max="10000"
                            value="{{ $category?->position ?? 0 }}" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="{{ $modalId }}-access">{{ __('support::admin.categories.access') }}</label>
                        <select class="form-select" id="{{ $modalId }}-access" name="access">
                            @foreach (['members', 'roles'] as $access)
                                <option value="{{ $access }}" @selected(($category?->access ?? 'members') === $access)>
                                    {{ __('support::admin.categories.access_' . $access) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <fieldset class="col-12">
                        <legend class="fs-6">{{ __('support::admin.categories.roles') }}</legend>
                        @foreach ($roles as $role)
                            <label class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="roles[]" value="{{ $role->id }}"
                                    @checked($category && ($categoryRoles[$category->id] ?? collect())->contains('role_id', $role->id))>
                                <span class="form-check-label">{{ $role->name }}</span></label>
                        @endforeach
                    </fieldset>
                    @if ($category?->permission)
                        <div class="col-12">
                            <p class="form-text">
                                {{ __('support::admin.categories.legacy_permission', ['permission' => $category->permission]) }}</p>
                            <label class="form-check">
                                <input class="form-check-input" name="replace_legacy_permission" type="checkbox" value="1">
                                <span class="form-check-label">{{ __('support::admin.categories.replace_legacy') }}</span></label>
                        </div>
                    @endif
                    <div class="col-12">
                        <label class="form-label" for="{{ $modalId }}-introduction">{{ __('support::admin.settings.introduction') }}</label>
                        <textarea class="form-control" id="{{ $modalId }}-introduction" name="introduction" rows="4" data-editor="tinymce">{{ $category?->introduction }}</textarea>
                    </div>
                    <div class="col-12">
                        <input type="hidden" name="structured_form_only" value="0">
                        <label class="form-check">
                            <input class="form-check-input" name="structured_form_only" value="1" type="checkbox"
                                aria-describedby="{{ $modalId }}-structured-help" @checked($category?->structured_form_only)>
                            <span class="form-check-label">{{ __('support::admin.categories.structured_form_only') }}</span>
                        </label>
                        <p class="form-text mb-0" id="{{ $modalId }}-structured-help">
                            {{ __('support::admin.categories.structured_form_only_help') }}
                        </p>
                    </div>
                    @foreach (['first_response_minutes', 'resolution_minutes', 'auto_close_hours'] as $field)
                        <div class="col-md-4">
                            <label class="form-label" for="{{ $modalId }}-{{ $field }}">{{ __('support::admin.deadlines.' . $field) }}</label>
                            <input class="form-control" id="{{ $modalId }}-{{ $field }}" type="number" name="{{ $field }}" min="1"
                                max="{{ $field === 'auto_close_hours' ? 8760 : 525600 }}" value="{{ $category?->$field }}"
                                aria-describedby="{{ $modalId }}-deadline-help">
                        </div>
                    @endforeach
                    <div class="col-12 form-text" id="{{ $modalId }}-deadline-help">
                        {{ __('support::admin.deadlines.deadline_help') }}
                    </div>
                    <div class="col-12">
                        <input type="hidden" name="auto_close_unanswered" value="0">
                        <label class="form-check">
                            <input class="form-check-input" name="auto_close_unanswered" value="1" type="checkbox"
                                aria-describedby="{{ $modalId }}-auto-close-help" @checked($category?->auto_close_unanswered)>
                            <span class="form-check-label">{{ __('support::admin.deadlines.auto_close_unanswered') }}</span>
                        </label>
                        <p class="form-text mb-0" id="{{ $modalId }}-auto-close-help">
                            {{ __('support::admin.deadlines.auto_close_unanswered_help') }}
                        </p>
                    </div>
                    <div class="col-12">
                        <input type="hidden" name="enabled" value="0">
                        <label class="form-check">
                            <input class="form-check-input" name="enabled" value="1" type="checkbox"
                                @checked($category?->enabled ?? true)>
                            <span class="form-check-label">{{ __('support::admin.categories.enabled') }}</span></label>
                    </div>
                </div>
            </x-forms.modal>
        @endforeach
    </div>
@endsection
