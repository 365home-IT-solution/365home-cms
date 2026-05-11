@props(['field'])
<textarea id="{{ $field->name ?? '' }}" wire:model="formData.{{ $field->name ?? '' }}"
    placeholder="Nhập {{ $field->label }}..."
    class="w-full focus:border-primary px-3 py-2 text-base text-gray-700 border 
        border-gray-300 rounded-md focus:outline-none transition duration-150 ease-in-out focus:border-input"
    rows="3"
    @if ($field->is_required ?? false) required @endif></textarea>