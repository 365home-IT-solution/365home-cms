@props(['field'])
<label for="{{ $field->name ?? '' }}" class="block text-sm font-medium text-gray-700 mb-2">
    {{ $field->label ?? 'Untitled Field' }}
    @if ($field->is_required ?? false)
        <span class="text-red-500 ml-1">*</span>
    @endif
</label>