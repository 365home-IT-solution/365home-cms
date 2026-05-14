
<div x-data="{ open: false }" class="space-y-1 mt-2">
    <div class="flex items-center justify-between group" data-category-name="{{ $category->name }}" data-category-slug="{{ $category->slug }}">
        <div class="flex items-center">
            <input wire:model.live="selectedCategory" id="category-{{ $category->slug }}" value="{{ $category->slug }}" name="category" type="radio" class="h-4 w-4 colorPrimary focus:ring-indigo-500 border-gray-300 transition-all duration-200 ease-in-out">
            <label for="category-{{ $category->slug }}" class="ml-3 block text-sm font-medium text-gray-700 hoverPrimaryColor transition-colors duration-200">
                {{ $category->name }}
            </label>
        </div>
        @if($category->children->isNotEmpty() && $level < 2)
            <button @click="open = !open" class="flex items-center text-xs text-gray-500 hoverPrimaryColor focus:outline-none transition-colors duration-200">
                <span x-show="!open" class="mr-1">Mở</span>
                <span x-show="open" class="mr-1">Đóng</span>
                <svg class="h-4 w-4 transform transition-transform duration-200" x-bind:class="{'rotate-180': open}" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                </svg>
            </button>
        @elseif($category->children->isNotEmpty() && $level >= 1)
            <button @click="open = !open" class="flex items-center text-xs text-gray-500 hoverPrimaryColor focus:outline-none transition-colors duration-200">
                <span x-show="!open" class="mr-1">+</span>
                <span x-show="open" class="mr-1">-</span>
            </button>
        @endif
    </div>
    @if($category->children->isNotEmpty())
        <div x-show="open"
             x-transition:enter="transition ease-out duration-100"
             x-transition:enter-start="opacity-0 transform scale-95"
             x-transition:enter-end="opacity-100 transform scale-100"
             x-transition:leave="transition ease-in duration-75"
             x-transition:leave-start="opacity-100 transform scale-100"
             x-transition:leave-end="opacity-0 transform scale-95"
             class="mt-2 pl-{{ $level < 1 ? '4' : '0' }}">
            <div class="bg-white {{ $level < 1 ? 'shadow overflow-hidden sm:rounded-md' : '' }}">
                <ul class="divide-y divide-gray-200">
                    @foreach($category->children as $childCategory)
                        <li class="px-{{ $level < 1 ? '4' : '0' }} py-2">
                            <x-bladethemev1::category-item :category="$childCategory" :primaryColor="$primaryColor" :level="$level + 1" />
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif
</div>
<style>
    .hoverPrimaryColor:hover {
        color: {{ $primaryColor }};
    }
    .colorPrimary {
        color: {{ $primaryColor }};
    }
</style>