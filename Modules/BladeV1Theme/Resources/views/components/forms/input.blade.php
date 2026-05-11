@props(['field'])
<input type="{{ $field->type ?? 'text' }}" id="{{ $field->name ?? '' }}" wire:model="formData.{{ $field->name ?? '' }}"
    placeholder="Nhập {{ $field->label }}..."
    class="w-full px-3 py-2 text-base text-gray-700 focus:outline-none rounded-md transition duration-150
        ease-in-out border-gray-300 focus:border-input"
    @if ($field->is_required ?? false) required @endif>
