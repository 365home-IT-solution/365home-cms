@props(['field'])
<input type="file" id="{{ $field->name ?? '' }}" wire:model="formData.{{ $field->name ?? '' }}"
    class="w-full px-3 py-2 text-base border border-gray-300 rounded-md 
        focus:outline-none transition duration-150 ease-in-out focus:border-input"
    @if ($field->is_required ?? false) required @endif>
