<div class="hidden lg:block w-full mt-4 bg-white rounded overflow-hidden shadow-lg p-4">
    <h2 class="text-lg font-semibold text-gray-800">Đăng ký & theo dõi</h2>
    <div class="h-px bg-gray-200 w-full mb-4"></div>
    <div class="flex space-x-3">
        <ul class="grid grid-cols-2 gap-4 w-full text-gray-500 dark:text-gray-400 font-medium">
            @foreach ($showSocial as $social)
                @switch($social['platform'])
                    @case('facebook')
                        @php $color = 'text-blue-600'; @endphp
                        @break

                    @case('twitter')
                        @php $color = 'text-blue-400'; @endphp
                        @break

                    @case('instagram')
                        @php $color = 'text-pink-600'; @endphp
                        @break

                    @case('youtube')
                        @php $color = 'text-red-600'; @endphp
                        @break

                    @case('linkedin')
                        @php $color = 'text-blue-700'; @endphp
                        @break

                    @case('pinterest')
                        @php $color = 'text-red-500'; @endphp
                        @break

                    @default
                        @php $color = 'text-gray-500'; @endphp
                @endswitch
                <a href="{{ $social['url'] }}" class="flex items-center justify-center space-x-2 p-2 bg-gray-100 rounded-lg hover:bg-gray-200">
                    <i class="{{ $social['icon'] }} {{ $color }}"></i>
                    <span>{{ ucfirst($social['platform']) }}</span>
                </a>
            @endforeach
        </ul>
    </div>
</div>
