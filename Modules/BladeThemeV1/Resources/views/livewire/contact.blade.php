<div class="">
    @if ($iframeCode)
        <div id="map" class="my-6 relative h-[300px] overflow-hidden bg-cover bg-[50%] bg-no-repeat rounded-xl"
            loading="lazy">
            {!! $iframeCode !!}
        </div>
    @endif

    @if ($form)
        <div
            class="mb-8 block px-6 md:px-12 mt-1 {{ $contactGroup ? 'py-12 md:py-16 rounded-lg shadow-[0_2px_15px_-3px_rgba(0,0,0,0.07),0_10px_20px_-2px_rgba(0,0,0,0.04)]  backdrop-blur-[30px] border border-gray-300' : 'py-12' }}">
            <div class="flex md:flex-row flex-col items-center space-x-3">
                <div
                    class="w-full lg:w-6/12 px-3 {{ !$contactGroup ? 'mx-auto' : '' }} transition-all duration-300 bg-gray-50 rounded-lg shadow-md hover:shadow-lg">
                    @livewire('bladethemev1::form-contact', [
                        'form' => $form,
                    ])
                </div>
                @if ($contactGroup)
                    <div class="w-full lg:w-6/12 px-3">
                        <div class="grid grid-cols-1 gap-6 mt-6">

                            @foreach ($contactGroup as $item)
                                <div class="group relative overflow-hidden rounded-lg hover-rotat">
                                    <div class="flex items-start md:space-x-4 space-x-2 md:p-4 p-2">
                                        <div class="flex-shrink-0 ">
                                            <div
                                                class="bg-primary group-hover:bg-white hover:shadow-lg rounded-full p-3 text-white transition-all duration-300 group-hover:shadow-md rotat-icon group-hover:text-primary">
                                                <x-dynamic-component :component="$item['icon']" class="md:w-6 md:h-6 w-4 h-4" />
                                            </div>
                                        </div>
                                        <div class="flex-grow">
                                            <div
                                                class="md:text-md text-sm font-semibold mb-2 transition-all duration-300 group-hover:text-primary">
                                                {{ $item['name'] }}</div>
                                            @if (!empty($item['tel']))
                                                <a class="md:text-md text-sm text-gray-600 hover:underline transition-all duration-300 group-hover:text-gray-800"
                                                    href="{{ $item['tel'] }}">{{ $item['value'] }}</a>
                                            @elseif(!empty($item['mail']))
                                                <a class="md:text-md text-sm text-gray-600 hover:underline transition-all duration-300 group-hover:text-gray-800"
                                                    href="{{ $item['mail'] }}">{{ $item['value'] }}</a>
                                            @else
                                                <p
                                                    class="md:text-md text-sm text-gray-600 transition-all duration-300 group-hover:text-gray-800">
                                                    {{ $item['value'] }}</p>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach

                            <div class="group relative overflow-hidden rounded-lg hover-rotat">
                                <a href="https://www.tiktok.com/@365.home?_r=1&_t=ZS-92VPwUYrnlg" target="_blank" class="flex items-start md:space-x-4 space-x-2 md:p-4 p-2">
                                    <div class="flex-shrink-0 ">
                                        <div class="bg-white group-hover:bg-white hover:shadow-lg rounded-full text-white transition-all duration-300 group-hover:shadow-md rotat-icon group-hover:text-primary">
                                            <img src="https://cdn-icons-png.flaticon.com/128/3116/3116491.png" class="md:w-12 md:h-12 w-12 h-12 object-contain" alt="Tiktok">
                                        </div>
                                    </div>
                                    <div class="flex-grow">
                                        <div class="md:text-md text-sm font-semibold mb-2 transition-all duration-300 group-hover:text-primary">Tiktok</div>
                                        <p class="md:text-md text-sm text-gray-600 hover:underline transition-all duration-300 group-hover:text-gray-800">https://www.tiktok.com/@365.home
                                        </p>
                                    </div>
                                </a>
                            </div>

                             <div class="group relative overflow-hidden rounded-lg hover-rotat">
                                <a href="https://www.facebook.com/365home.254xuanthuy.cantho" target="_blank" class="flex items-start md:space-x-4 space-x-2 md:p-4 p-2">
                                    <div class="flex-shrink-0 ">
                                        <div class="bg-white group-hover:bg-white hover:shadow-lg rounded-full text-white transition-all duration-300 group-hover:shadow-md rotat-icon group-hover:text-primary">
                                            <img src="https://cdn-icons-png.flaticon.com/128/5968/5968764.png" class="md:w-12 md:h-12 w-12 h-12 object-contain" alt="Facebook">
                                        </div>
                                    </div>
                                    <div class="flex-grow">
                                        <div class="md:text-md text-sm font-semibold mb-2 transition-all duration-300 group-hover:text-primary">Facebook</div>
                                        <p class="md:text-md text-sm text-gray-600 hover:underline transition-all duration-300 group-hover:text-gray-800">https://www.facebook.com/365home.254xuanthuy.cantho</p>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @elseif($contactGroup)
        <div class="bg-white relative">
            <div
                class="my-6 rounded-lg shadow-lg grid grid-cols-1 sm:grid-cols-2 
            @if (count($contactGroup) === 4) lg:grid-cols-4 
            @elseif (count($contactGroup) === 3) md:grid-cols-3 @endif gap-4">

                @foreach ($contactGroup as $index => $item)
                    <div class="group h-full relative hover-rotat">
                        <div class="flex flex-col justify-between p-3 text-center">
                            <div
                                class="inline-flex items-center justify-center  w-12 h-12 bg-primary rounded-full text-white
                                    transition-all duration-300  group-hover:bg-white group-hover:shadow-lg mx-auto text-white rotat-icon group-hover:text-primary">
                                <x-dynamic-component :component="$item['icon']" class="md:w-6 md:h-6 w-4 h-4 " />
                            </div>
                            <h5
                                class="mt-3 mb-1 text-lg font-semibold transition-all duration-300 group-hover:text-primary">
                                {{ $item['name'] }}</h5>
                            @if (!empty($item['tel']))
                                <a class="mb-0 hover:underline transition-all duration-300 group-hover:text-gray-800"
                                    href="{{ $item['tel'] }}">{{ $item['value'] }}</a>
                            @elseif(!empty($item['mail']))
                                <a class="mb-0 hover:underline transition-all duration-300 group-hover:text-gray-800"
                                    href="{{ $item['mail'] }}">{{ $item['value'] }}</a>
                            @else
                                <p class="mb-0 transition-all duration-300 group-hover:text-gray-800">
                                    {{ $item['value'] }}</p>
                            @endif
                        </div>

                        @if (!$loop->last)
                            <span
                                class="hidden lg:block lg:absolute top-0 right-0 lg:h-36 lg:border-r lg:border-gray-300"></span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

    @endif

    @if ($branches)
        <div class="grid grid-cols-1 md:grid-cols-2 mt-8 gap-8">
            @foreach ($branches as $item)
                <div
                    class="mb-5 p-6 rounded-lg shadow-xl border-4 border-primary transition-all duration-300 transform hover:-translate-y-2 bg-white hover:bg-gray-50 group">
                    @if (!empty($item['icon']))
                        <div
                            class="flex justify-center items-center bg-gradient shadow-lg rounded-full w-16 h-16 mb-4 group-hover:scale-110 transition-transform duration-300">
                            <x-dynamic-component :component="$item['icon']" class="w-6 h-6 text-primary animate-bounce" />
                        </div>
                    @endif
                    <h2
                        class="text-2xl text-gray-900 font-medium group-hover:text-primary transition-colors duration-300">
                        {{ $item['title'] }}</h2>
                    @if (!empty($item['description']))
                        <p class="mt-2 pr-5 mb-3 group-hover:text-gray-600">{{ $item['description'] }}</p>
                    @endif

                    @if (!empty($item['branch']['address']))
                        <div class="flex items-center p-2 hover:bg-white/50 rounded transition-all duration-300">
                            <i class="fas fa-map text-primary group-hover:scale-110 transition-transform"></i>
                            <span
                                class="pl-3 group-hover:translate-x-1 transition-transform">{{ $item['branch']['address'] }}</span>
                        </div>
                    @endif

                    @if (!empty($item['branch']['email']))
                        <div class="flex items-center p-2 hover:bg-white/50 rounded transition-all duration-300">
                            <i class="fas fa-envelope text-primary group-hover:scale-110 transition-transform"></i>
                            <span
                                class="pl-3 group-hover:translate-x-1 transition-transform">{{ $item['branch']['email'] }}</span>
                        </div>
                    @endif

                    @if (!empty($item['branch']['phone']))
                        <div class="flex items-center p-2 hover:bg-white/50 rounded transition-all duration-300">
                            <i class="fas fa-phone text-primary group-hover:scale-110 transition-transform"></i>
                            <span
                                class="pl-3 group-hover:translate-x-1 transition-transform">{{ $item['branch']['phone'] }}</span>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif

</div>
