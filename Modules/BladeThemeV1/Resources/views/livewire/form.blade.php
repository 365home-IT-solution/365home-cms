<div
    class="fixed inset-0 z-50 bg-gray-100 bg-opacity-75 overflow-y-auto h-full w-full p-4 flex flex-col justify-center items-center">

    <form class="m-0 w-full max-w-xl bg-white shadow-md rounded-lg" wire:submit="submitForm">
        @csrf
        @isset($form->name)
            <div class="border-b w-full max-w-2xl md:p-6 p-4">
                <div class="text-xl text-left text-gray-700">

                    {{ $form->name ?? 'Cần chọn biểu mẫu' }}
                    <span class="text-primary font-medium">{{ $this->title ?? '' }}</span>

                </div>
            </div>
        @endisset
        <div class="md:p-6 p-4">

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

        </div>

        <div class="border-t w-full max-w-2xl shadow-md">
            <div class="flex justify-end gap-2 md:p-6 p-4">
                <x-bladethemev1::buttons.button :style="'close1'" :text_size="'md:text-md text-sm'" type="button"
                    x-on:click="closeModal()">
                    <span>Đóng</span>
                    <div class="svg-wrapper-1">
                        <div class="svg-wrapper">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24">
                                <path
                                    d="M24 20.188l-8.315-8.209 8.2-8.282-3.697-3.697-8.212 8.318-8.31-8.203-3.666 3.666 8.321 8.24-8.206 8.313 3.666 3.666 8.237-8.318 8.285 8.203z">
                                </path>
                            </svg>
                        </div>
                    </div>
                </x-bladethemev1::buttons.button>
                @isset ($form->name)
                    <x-bladethemev1::buttons.button wire:loading.attr="disabled"
                        wire:loading.class="opacity-50 cursor-not-allowed" :text_size="'md:text-md text-sm'" :style="'1'">
                        <span wire:loading.remove>{{ $form->submit_button_text ?? 'Gửi' }}</span>
                        <span wire:loading>Đang xử lý...</span>

                        <svg wire:loading.remove
                            class="w-6 h-6 justify-end group-hover:rotate-90 group-hover:bg-primary text-white ease-linear duration-300 rounded-full border border-white group-hover:border-none p-[5px] rotate-45"
                            viewBox="0 0 16 19" viewBox="0 0 16 19" xmlns="http://www.w3.org/2000/svg">
                            <path
                                d="M7 18C7 18.5523 7.44772 19 8 19C8.55228 19 9 18.5523 9 18H7ZM8.70711 0.292893C8.31658 -0.0976311 7.68342 -0.0976311 7.29289 0.292893L0.928932 6.65685C0.538408 7.04738 0.538408 7.68054 0.928932 8.07107C1.31946 8.46159 1.95262 8.46159 2.34315 8.07107L8 2.41421L13.6569 8.07107C14.0474 8.46159 14.6805 8.46159 15.0711 8.07107C15.4616 7.68054 15.4616 7.04738 15.0711 6.65685L8.70711 0.292893ZM9 18L9 1H7L7 18H9Z"
                                class="fill-white group-hover:fill-white"></path>
                        </svg>
                        <svg class="animate-spin" wire:loading height="16" width="16" viewBox="0 0 24 24"
                            xmlns="http://www.w3.org/2000/svg">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                stroke-width="4">
                            </circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                            </path>
                        </svg>

                    </x-bladethemev1::buttons.button>
                @endisset
            </div>
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
