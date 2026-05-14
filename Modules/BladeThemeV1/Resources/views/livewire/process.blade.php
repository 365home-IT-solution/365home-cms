<div class="max-w-8xl mx-auto rounded-lg md:p-8 p-0">
    @if ($steps)
        @if ($process_style == 'flow_diagram')
            <div class="flex flex-wrap lg:flex-nowrap justify-center items-start mb-8 space-y-4 lg:space-y-0 relative">
                @foreach ($steps as $index => $step)
                    <div class="flex flex-col px-2 md:my-0 my-4 items-center w-full relative transition-all duration-1000 transform translate-y-10"
                         data-scroll>
                        <div
                                class="absolute hidden -top-1 z-30 right-1/4 w-8 h-8 bg-gradient rounded-full md:flex items-center justify-center text-white font-bold shadow-md">
                            {{ $index + 1 }}
                        </div>
                        <div
                                class="w-24 h-24 z-20 {{ $colors[$index % count($colors)] }} rounded-full flex items-center justify-center mb-4 transition-transform duration-300 hover:scale-110 shadow-lg overflow-hidden">
                            <img class="p-2" src="{{ asset('storage/' . $step['icon']) }}" alt="{{ $step['name'] }}">
                        </div>
                        @if ($index < count($steps) - 1)
                            <div
                                    class="hidden lg:flex absolute top-11 left-[55%] transform -translate-y-1/2 z-10 w-full">
                                <svg viewBox="0 0 100 30" class="w-full h-12">
                                    <path d="M0 15 Q25 5, 40 15 T80 15" class="stroke-gray-400" stroke-width="2"
                                          stroke-linecap="round" stroke-linejoin="round" stroke-dasharray="4 4"
                                          fill="none">
                                        <animate attributeName="stroke-dashoffset" values="24;0" dur="1.5s"
                                                 repeatCount="indefinite" />
                                    </path>
                                </svg>
                            </div>
                        @endif
                        <h3 style="color: {{ $processTitleColor ?? '#262626' }}"
                            class="font-semibold text-center relative pb-2 after:content-[''] after:absolute after:bottom-0 after:left-1/2 after:-translate-x-1/2 after:w-8 after:h-0.5 after:bg-gray-500">
                            {{ $step['name'] }}
                        </h3>
                        <div class="mt-2 w-full">
                            <p style="color: {{ $processDescriptionColor ?? '#383838' }}"
                               class="text-xs custom-lheight text-center px-2">
                                {{ $step['description'] ?? '' }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        @elseif ($process_style == 'minimalist')
            <div class="flex flex-wrap lg:flex-nowrap justify-center items-start mb-8 space-y-4 lg:space-y-0 relative">
                <div class="flex flex-wrap justify-center items-start gap-y-6 relative">
                    <div class="grid grid-cols-1 items-start gap-10 mx-auto">
                        <div class="flex flex-col justify-start">
                            <div class="flex justify-center">
                                <ol class="relative left-3 border-s border-gray-200 dark:border-gray-700 max-w-3xl">
                                    @foreach ($steps as $index => $step)
                                        <li class="mb-10 ms-8">
                                            <span
                                                    class="absolute flex items-center justify-center w-10 h-10 bg-white shadow-2xl shadow-yellow-500 rounded-full -start-5 ring-8 ring-white dark:ring-gray-900">
                                                <img class="p-2" src="{{ asset('storage/' . $step['icon']) }}"
                                                     alt="{{ $step['name'] }}">
                                            </span>
                                            <h3 style="color: {{ $processTitleColor ?? '#262626' }}"
                                                class="flex items-center mb-1 md:text-lg text-md font-semibold pr-5">
                                                {{ $step['name'] }}</h3>
                                            <p style="color: {{ $processDescriptionColor ?? '#383838' }}"
                                               class="md:text-base text-sm font-normal text pr-5">
                                                {{ $step['description'] }}
                                            </p>
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @elseif ($process_style == 'row')
            <style>
                .service-divider {
                    height: 3px;
                    width: 40px;
                    background-color: #ffa500;
                    margin: 0 0 12px 0;
                }
            </style>
            <div class="max-w-7xl mx-auto px-4 py-8 relative overflow-hidden">

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-x-8 gap-y-16">
                    <div class="flex flex-col items-center">
                        <div class="flex justify-center mb-4">
                            <div class="flex items-center">
                                <div class="h-1 w-1 bg-primary mx-0.5"></div>
                                <div class="h-1 w-1 bg-primary mx-0.5"></div>
                                <div class="h-1 w-1 bg-primary mx-0.5"></div>
                                <div class="h-1 w-4 bg-primary mx-0.5"></div>
                            </div>
                            <div class="mx-4">
                                <img src="https://gas.goldenbeeltd.top/storage/01JRJRHSSSYJ0SJMXZEN92GPNC.png" alt="25 Logo"
                                     class="h-16 w-auto">
                            </div>
                            <div class="flex items-center">
                                <div class="h-1 w-1 bg-primary mx-0.5"></div>
                                <div class="h-1 w-1 bg-primary mx-0.5"></div>
                                <div class="h-1 w-1 bg-primary mx-0.5"></div>
                                <div class="h-1 w-4 bg-primary mx-0.5"></div>
                            </div>
                        </div>

                        <div class="text-center">
                            <h2 class="text-4xl font-bold text-gray-900 tracking-wide">DỊCH VỤ</h2>
                        </div>
                    </div>
                    @foreach ($steps as $index => $step)
                        <div class="flex flex-col items-start">
                            <div class="w-20 h-20 bg-primary rounded-full flex items-center justify-center mb-4">
                                <img class="w-16 h-16 object-contain filter brightness-0 invert"
                                     src="{{ asset('storage/' . $step['icon']) }}" alt="{{ $step['name'] }}">
                            </div>
                            <h3 class="font-bold text-xl uppercase mb-2"
                                style="color: {{ $processTitleColor ?? '#262626' }}">{{ $step['name'] ?? '' }}</h3>
                            <div class="service-divider"></div>
                            <p class="text-lg" style="color: {{ $processDescriptionColor ?? '#383838' }}">
                                {{ $step['description'] ?? '' }}</p>
                        </div>
                    @endforeach
                    <!-- Empty slot for balance on large screens -->
                    <div class="hidden lg:block"></div>
                </div>
            </div>
        @elseif ($process_style == 'basic')
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 justify-center items-start relative">
                @foreach ($steps as $index => $step)
                    <div class="p-4 border rounded-lg shadow-lg flex flex-col items-start bg-white">
                        <div class="w-full h-48 mb-4 overflow-hidden rounded-lg">
                            <img class="w-full h-full object-cover" src="{{ asset('storage/' . $step['icon']) }}"
                                 alt="{{ $step['name'] }}">
                        </div>
                        <h3 style="color: {{ $processTitleColor ?? '#262626' }}" class="text-lg font-semibold mb-2">
                            {{ $step['name'] }}
                        </h3>
                        <p style="color: {{ $processDescriptionColor ?? '#383838' }}" class="text-sm font-normal">
                            {{ $step['description'] }}
                        </p>
                    </div>
                @endforeach
            </div>
        @elseif ($process_style == 'basic')
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 justify-center items-start relative">
                @foreach ($steps as $index => $step)
                    <div class="p-4 border rounded-lg shadow-lg flex flex-col items-start bg-white">
                        <div class="w-full h-48 mb-4 overflow-hidden rounded-lg">
                            <img class="w-full h-full object-cover" src="{{ asset('storage/' . $step['icon']) }}" alt="{{ $step['name'] }}">
                        </div>
                        <h3 style="color: {{ $processTitleColor ?? '#262626' }}" class="text-lg font-semibold mb-2">
                            {{ $step['name'] }}
                        </h3>
                        <p style="color: {{ $processDescriptionColor ?? '#383838' }}" class="text-sm font-normal">
                            {{ $step['description'] }}
                        </p>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>