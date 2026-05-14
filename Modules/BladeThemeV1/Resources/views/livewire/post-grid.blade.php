<div>
    <div class="grid gap-4 md:gap-y-4 gap-y-8 grid-cols-1
            @if ($smColumns == 1) sm:grid-cols-1
            @elseif($smColumns == 2) sm:grid-cols-2
            @elseif($smColumns == 3) sm:grid-cols-3
            @elseif($smColumns == 4) sm:grid-cols-4 @endif
            @if ($mdColumns == 1) md:grid-cols-1
            @elseif($mdColumns == 2) md:grid-cols-2
            @elseif($mdColumns == 3) md:grid-cols-3
            @elseif($mdColumns == 4) md:grid-cols-4 @endif
            @if ($lgColumns == 1) lg:grid-cols-1
            @elseif($lgColumns == 2) lg:grid-cols-2
            @elseif($lgColumns == 3) lg:grid-cols-3
            @elseif($lgColumns == 4) lg:grid-cols-4 @endif">
            @foreach ($posts as $post)
                @switch($config['style'] ?? 'default')
                    @case('overlay')
                        @include('bladethemev1::components.posts.overlay')
                        @break

                    @case('card')
                        @include('bladethemev1::components.posts.card')
                        @break

                    @case('minimal')
                        @include('bladethemev1::components.posts.minimal')
                        @break

                    @default
                        @include('bladethemev1::components.posts.default')
                @endswitch
            @endforeach
    </div>
    @if(!empty($config['show_pagination']) && $config['show_pagination'])
            <x-bladethemev1::paginate :items="$posts" />
    @endif
</div>
