<div class="rounded">
    <h2 class="text-gray-700 font-medium md:text-xl text-md py-6 text-center">{{ $form->name }} <span
            class="text-primary">{{ $this->title }}</span>
    </h2>
    <form wire:submit="submitForm" class="md:p-4 p-3 rounded-lg">
        @csrf

        <div class="grid grid-cols-1 gap-5">
        @if ($formFields)
                    @foreach ($formFields as $field)
                        <div class="col-span-2 sm:col-span-1 text-left">
                            <x-bladethemev1::forms.title :field="$field" />

                            @switch($field->type ?? '')
                                @case('textarea')
                                    <x-bladethemev1::forms.textarea :field="$field" />
                                @break

                                @case('select')
                                    <x-bladethemev1::forms.select :field="$field" />
                                @break

                                @case('radio')
                                    <x-bladethemev1::forms.radio :field="$field" />
                                @break

                                @case('file')
                                    <x-bladethemev1::forms.file :field="$field" />
                                @break

                                @default
                                    <x-bladethemev1::forms.input :field="$field" />
                            @endswitch

                            @error('formData.' . ($field->name ?? ''))
                                <p class="mt-1 text-red-500 text-xs">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach
                @else
                    <div class="col-span-2 sm:col-span-1 text-left">
                        <div class="md:text-lg text-md text-red-500">Biểu mẫu trống !</div>
                    </div>
                @endif
        </div>

        <div class="flex justify-end mt-6">
            <x-bladethemev1::buttons.button type="submit" wire:loading.attr="disabled" :text_size="'md:text-md text-sm'"
                wire:loading.class="opacity-50 cursor-not-allowed" :style="'1'">
                <span wire:loading.remove>{{ $form->submit_button_text ?? 'Gửi' }}</span>
                <span wire:loading>Đang xử lý...</span>
                <div class="icon">
                    <svg height="16" width="16" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"
                        wire:loading.remove>
                        <path d="M0 0h24v24H0z" fill="none"></path>
                        <path d="M16.172 11l-5.364-5.364 1.414-1.414L20 12l-7.778 7.778-1.414-1.414L16.172 13H4v-2z"
                            fill="currentColor"></path>
                    </svg>
                    <svg class="animate-spin" wire:loading height="24" width="24" viewBox="0 0 24 24"
                        xmlns="http://www.w3.org/2000/svg">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                            stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                        </path>
                    </svg>
                </div>
            </x-bladethemev1::buttons.button>
        </div>
    </form>

    <div x-data="{ showMessage: false, message: '', type: '' }" x-init="window.addEventListener('show-message', (event) => {
        showMessage = true;
        message = event.detail[0].message;
        type = event.detail[0].type;
        setTimeout(() => { showMessage = false }, 5000);
    })" x-show="showMessage"
        x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 transform scale-90"
        x-transition:enter-end="opacity-100 transform scale-100" x-transition:leave="transition ease-in duration-300"
        x-transition:leave-start="opacity-100 transform scale-100" x-transition:leave-end="opacity-0 transform scale-90"
        class="fixed bottom-5 right-0 left-0 px-4 w-2/3 mx-auto text-center py-2 rounded-md shadow-lg text-white font-semibold"
        :class="{ 'bg-green-500 bg-opacity-70': type === 'success', 'bg-red-500': type === 'error' }"
        style="display: none;">
        <p x-text="message"></p>
    </div>
</div>

<script>
    document.addEventListener('livewire:load', function() {
        Livewire.on('show-message', (data) => {
            window.dispatchEvent(new CustomEvent('show-message', {
                detail: data
            }));
        });
    });
</script>

