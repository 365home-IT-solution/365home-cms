<ul class="grid gap-x-12 [&>.feature-1]:pl-0 sm:grid-cols-2 sm:gap-y-8 lg:grid-cols-3">
    @foreach ($services as $service)
        <li class="space-y-3 py-8 sm:py-0 lg:!text-start text-center group hover:-translate-y-2 transition-all duration-300">
            <div class="w-16 h-16 rounded-xl flex items-center justify-center mx-auto lg:!mx-0 group-hover:scale-110 transition-transform duration-300 relative">
                @if ($service['icon'])
                    <div class="absolute inset-0 bg-gradient opacity-0 group-hover:opacity-20 rounded-xl transition-opacity duration-300"></div>
                    <img class="p-3 w-full h-full group-hover:rotate-6 transition-transform duration-300"
                         src="{{ asset('/storage/' . $service['icon']) }}"
                         alt="{{ $service['name'] ?? 'icon' }}">
                @endif
            </div>
            <h4 class="text-xl text-gray-700 font-medium text-gradient bg-gradient group-hover:scale-105 transition-transform duration-300">
                {{ $service['name'] ?? '' }}
            </h4>
            <p class="group-hover:text-gray-600 transition-colors duration-300">
                {{ $service['description'] ?? '' }}
            </p>
        </li>
    @endforeach
 </ul>