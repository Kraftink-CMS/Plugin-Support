@use('Illuminate\Support\Collection')
@use('KraftInk\Plugins\Support\Models\ReplyTemplate')
@php
    /** @var Collection<int, ReplyTemplate> $templates */
@endphp
@if ($templates->isNotEmpty())
    <div class="mb-3">
        <label class="form-label" for="support-template">{{ __('support::admin.templates.choose_template') }}</label>
        <div class="input-group">
            <select class="form-select" id="support-template" data-support-template>
                <option value="">{{ __('support::messages.search.none') }}</option>
                @foreach ($templates as $template)
                    <option value="{{ $template->id }}" data-body="{{ $template->body }}">{{ $template->title }}</option>
                @endforeach
            </select>
            <button class="btn btn-outline-secondary" type="button" data-support-apply-template
                data-confirm-replace="{{ __('support::admin.templates.replace_body') }}">{{ __('support::admin.templates.apply_template') }}</button>
        </div>
    </div>
@endif
