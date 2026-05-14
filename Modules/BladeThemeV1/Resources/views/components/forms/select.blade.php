@props(['field'])
<select id="{{ $field->name ?? '' }}" wire:model="formData.{{ $field->name ?? '' }}"
    class="w-full px-3 py-2 text-base text-gray-700 border border-gray-300 
        rounded-md focus:outline-none transition duration-150 ease-in-out focus:border-input"
    @if ($field->is_required ?? false) required @endif>
    <option value="">Chọn {{ strtolower($field->label ?? 'option') }}</option>
    @foreach (explode('|', $field->options ?? '') as $option)
        @php
            $optionParts = explode(',', $option);
            $optionValue = trim($optionParts[0] ?? '');
            $optionLabel = isset($optionParts[1])
                ? trim($optionParts[1])
                : $optionValue;
        @endphp
        <option value="{{ $optionValue }}">{{ $optionLabel }}</option>
    @endforeach
</select>