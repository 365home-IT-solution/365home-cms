<button type="submit" class="cssbuttons-io-button" wire:loading.attr="disabled"
    wire:loading.class="opacity-50 cursor-not-allowed">
    <span wire:loading.remove>{{ $form->submit_button_text ?? 'Gửi' }}</span>
    <span wire:loading>Đang xử lý...</span>
    <div class="icon">
        <svg height="24" width="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" wire:loading.remove>
            <path d="M0 0h24v24H0z" fill="none"></path>
            <path d="M16.172 11l-5.364-5.364 1.414-1.414L20 12l-7.778 7.778-1.414-1.414L16.172 13H4v-2z"
                fill="currentColor"></path>
        </svg>
        <svg class="animate-spin" wire:loading height="24" width="24" viewBox="0 0 24 24"
            xmlns="http://www.w3.org/2000/svg">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
            </circle>
            <path class="opacity-75" fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
            </path>
        </svg>
    </div>
</button>
