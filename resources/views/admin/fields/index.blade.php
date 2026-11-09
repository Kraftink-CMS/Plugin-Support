@use('Illuminate\Pagination\LengthAwarePaginator')
@use('KraftInk\Plugins\Support\Models\Field')
@use('Illuminate\Support\Collection')
@use('KraftInk\Plugins\Support\Models\Category')
@php
    /** @var LengthAwarePaginator<int, Field> $fields */
    /** @var Collection<int, Category> $categories */
@endphp
@extends('admin.layouts.layout')
@section('title', __('support::admin.settings.forms'))
@section('actions')
    @if ($categories->isNotEmpty())
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#support-field-new">
            {{ __('support::admin.settings.new_field') }}</button>
    @endif
@endsection
@section('content')
    <div id="support-admin-fields" data-ajax-pagination data-table tabindex="-1" class="vstack gap-4">
        @if ($categories->isEmpty())
            <p class="alert alert-info">{{ __('support::admin.forms.create_category_first') }}</p>
        @else
            <div class="card">
                <x-tables.controls :paginator="$fields" container-class="card-body" />
                <div class="card-body p-0 table-responsive">
                    <table class="table align-middle mb-0">
                        <thead class="text-nowrap">
                            <tr>
                                <th scope="col" data-table-sort="label">{{ __('support::admin.forms.field_label') }}</th>
                                <th scope="col" data-table-sort="category">{{ __('support::messages.tickets.category') }}</th>
                                <th scope="col" data-table-sort="type">{{ __('support::admin.forms.field_type') }}</th>
                                <th>{{ __('support::messages.search.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($fields as $field)
                                <tr>
                                    <td>{{ $field->label }}
                                        @if ($field->staff_only)
                                            <span
                                                class="badge text-bg-secondary">{{ __('support::admin.forms.staff_only') }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $categories->firstWhere('id', $field->category_id)?->name }}</td>
                                    <td>{{ __('support::admin.forms.type_' . $field->type) }}</td>
                                    <td>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                                                data-bs-target="#support-field-{{ $field->id }}">{{ __('support::admin.settings.edit') }}</button>
                                            <form data-ajax="true" data-redirect-url="response" method="post" action="{{ route('plugins.support.admin.fields.destroy', $field->id) }}"
                                                data-confirm="{{ __('support::admin.forms.confirm_delete_field') }}">
                                                @csrf
                                                @method('DELETE')
                                                <button
                                                    class="btn btn-sm btn-outline-danger">{{ __('support::messages.tickets.delete') }}</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <x-tables.empty :colspan="4" :message="__('support::messages.tickets.empty')" />
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">
                    {{ $fields->links() }}
                </div>
            </div>
            @foreach ($fields->concat([null]) as $field)
                @php
                    $modalId = 'support-field-' . ($field?->id ?? 'new');
                @endphp
                <x-forms.modal :id="$modalId" :title="$field?->label ?? __('support::admin.settings.new_field')" :action="route('plugins.support.admin.fields.store', $field?->id)">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="{{ $modalId }}-category">{{ __('support::messages.tickets.category') }}</label>
                            <select class="form-select" id="{{ $modalId }}-category" name="category_id" required>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" @selected($field?->category_id === $category->id)>{{ $category->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="{{ $modalId }}-key">{{ __('support::admin.forms.field_key') }}</label>
                            <input class="form-control" id="{{ $modalId }}-key" name="key" maxlength="60" pattern="[a-z][a-z0-9_]*"
                                value="{{ $field?->key }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="{{ $modalId }}-label">{{ __('support::admin.forms.field_label') }}</label>
                            <input class="form-control" id="{{ $modalId }}-label" name="label" maxlength="120"
                                value="{{ $field?->label }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="{{ $modalId }}-type">{{ __('support::admin.forms.field_type') }}</label>
                            <select class="form-select" id="{{ $modalId }}-type" name="type">
                                @foreach (Field::TYPES as $type)
                                    <option value="{{ $type }}" @selected(($field?->type ?? 'text') === $type)>
                                        {{ __('support::admin.forms.type_' . $type) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="{{ $modalId }}-position">{{ __('support::messages.tickets.position') }}</label>
                            <input class="form-control" id="{{ $modalId }}-position" name="position" type="number" min="0" max="10000"
                                value="{{ $field?->position ?? 0 }}" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="{{ $modalId }}-help-text">{{ __('support::admin.forms.field_help_text') }}</label>
                            <input class="form-control" id="{{ $modalId }}-help-text" name="help_text" maxlength="500"
                                value="{{ $field?->help_text }}">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="{{ $modalId }}-options">{{ __('support::admin.forms.field_options') }}</label>
                            <textarea class="form-control" id="{{ $modalId }}-options" name="options" rows="4" maxlength="5000"
                                aria-describedby="{{ $modalId }}-options-help">{{ $field ? implode("\n", ($field->options ?? [])) : '' }}</textarea>
                            <p class="form-text" id="{{ $modalId }}-options-help">{{ __('support::admin.forms.options_help') }}</p>
                        </div>
                        <div class="col-12">
                            @foreach (['required', 'staff_only', 'enabled'] as $flag)
                                <input type="hidden" name="{{ $flag }}" value="0">
                                <label class="form-check">
                                    <input class="form-check-input" type="checkbox" name="{{ $flag }}" value="1"
                                        @checked($field?->$flag ?? $flag === 'enabled')>
                                    <span class="form-check-label">{{ __('support::admin.forms.' . $flag) }}</span></label>
                            @endforeach
                        </div>
                    </div>
                </x-forms.modal>
            @endforeach
        @endif
    </div>
@endsection
