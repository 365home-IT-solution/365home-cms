<form wire:submit="submitForm" class="flex flex-col sm:flex-row gap-2 w-full m-0">
    @csrf

    <div class="relative w-full">
        @foreach ($formFields as $field)
            @if($field->type == 'email')
                <input type="email" id="{{ $field->name }}"
                       wire:model="formData.{{ $field->name }}"
                       placeholder="Email của bạn"
                       @if ($field->is_required) required @endif
                       class="w-full pl-10 pr-4 py-3 rounded-lg border border-gray-300 focus:outline-none focus:border-gray-300 focus:ring-1 focus:ring-gray-300">
                <div class="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400" fill="none"
                         viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                </div>
            @endif
        @endforeach
    </div>
    <button
        class="whitespace-nowrap px-6 py-3 bg-primary text-white rounded-lg hover:opacity-90 transition"
        wire:loading.class="opacity-50 cursor-not-allowed"
        type="submit" wire:loading.attr="disabled"
    >
        <span wire:loading.remove>{{ $form->submit_button_text ?? 'Gửi' }}</span>
        <span wire:loading>Đang xử lý...</span>
        <svg class="animate-spin" wire:loading height="24" width="24" viewBox="0 0 24 24"
             xmlns="http://www.w3.org/2000/svg">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                    stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor"
                  d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
            </path>
        </svg>
    </button>
</form>
