@use('Illuminate\Support\Collection')
@use('KraftInk\Plugins\Support\Models\Field')
@use('KraftInk\Plugins\Support\Models\FieldValue')
@php
    /** @var Collection<int, Field> $fields */
    /** @var Collection<int, FieldValue> $fieldValues */
@endphp
@foreach ($fields as $field)
    @php
        $fieldValue = old(
            'custom_fields.' . $field->id,
            isset($fieldValues)
                ? $fieldValues->firstWhere('field_id', $field->id)?->value
                : ($draft['custom_fields'][$field->id] ?? null),
        );
        $fieldValue = is_scalar($fieldValue) ? $fieldValue : null;
        $inputId = 'custom_' . $field->id;
        $helpId = $field->help_text ? $inputId . '-help' : null;
    @endphp
    <div @class(['mb-3', 'form-check' => $field->type === 'checkbox'])>
        @if ($field->type === 'checkbox')
            <input type="hidden" name="custom_fields[{{ $field->id }}]" value="0">
            <input class="form-check-input" id="{{ $inputId }}" type="checkbox"
                name="custom_fields[{{ $field->id }}]" data-support-draft-field="{{ $field->id }}" value="1"
                @if ($helpId) aria-describedby="{{ $helpId }}" @endif
                @checked($fieldValue) @required($field->required)>
        @endif
        <label @class([
            'form-check-label' => $field->type === 'checkbox',
            'form-label d-block mb-2' => $field->type !== 'checkbox',
        ])
            for="{{ $inputId }}">{{ $field->label }}{{ $field->required ? ' *' : '' }}
            @if ($field->staff_only)
                <span class="badge text-bg-secondary">{{ __('support::messages.tickets.internal') }}</span>
            @endif
        </label>
        @if ($field->type === 'textarea')
            <textarea class="form-control" id="{{ $inputId }}" name="custom_fields[{{ $field->id }}]" rows="4"
                data-support-draft-field="{{ $field->id }}" maxlength="5000"
                @if ($helpId) aria-describedby="{{ $helpId }}" @endif @required($field->required)>{{ $fieldValue }}</textarea>
        @elseif($field->type === 'select')
            <select class="form-select" id="{{ $inputId }}" name="custom_fields[{{ $field->id }}]"
                data-support-draft-field="{{ $field->id }}"
                @if ($helpId) aria-describedby="{{ $helpId }}" @endif @required($field->required)>
                <option value="">{{ __('support::admin.forms.choose_option') }}</option>
                @foreach ($field->options as $option)
                    <option value="{{ $option }}" @selected($fieldValue === $option)>{{ $option }}</option>
                @endforeach
            </select>
        @elseif($field->type === 'number')
            <input class="form-control" id="{{ $inputId }}" type="number"
                name="custom_fields[{{ $field->id }}]" data-support-draft-field="{{ $field->id }}" value="{{ $fieldValue }}"
                @if ($helpId) aria-describedby="{{ $helpId }}" @endif @required($field->required)
                min="-999999999" max="999999999" step="any">
        @elseif($field->type !== 'checkbox')
            <input class="form-control" id="{{ $inputId }}" type="{{ $field->type === 'email' ? 'email' : 'text' }}"
                name="custom_fields[{{ $field->id }}]" data-support-draft-field="{{ $field->id }}" value="{{ $fieldValue }}"
                @if ($helpId) aria-describedby="{{ $helpId }}" @endif @required($field->required)
                maxlength="{{ $field->type === 'email' ? 254 : 500 }}">
        @endif
        @if ($helpId)
            <p class="form-text mb-0" id="{{ $helpId }}">{{ $field->help_text }}</p>
        @endif
    </div>
@endforeach
