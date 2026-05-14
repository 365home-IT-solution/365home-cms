@props(['field'])
<div class="mt-2">
    @foreach (explode('|', $field->options ?? '') as $option)
        @php
            $optionParts = explode(',', $option);
            $optionValue = trim($optionParts[0] ?? '');
            $optionLabel = isset($optionParts[1])
                ? trim($optionParts[1])
                : $optionValue;
        @endphp
        <div class="flex items-center mb-2">
            <input type="radio" id="{{ ($field->name ?? '') . '_' . $optionValue }}"
                name="{{ $field->name ?? '' }}" value="{{ $optionValue }}"
                wire:model="formData.{{ $field->name ?? '' }}" class="mr-2"
                @if ($field->is_required ?? false) required @endif>
            <label for="{{ ($field->name ?? '') . '_' . $optionValue }}"
                class="text-sm text-gray-700">
                {{ $optionLabel }}
            </label>
        </div>
    @endforeach
</div>