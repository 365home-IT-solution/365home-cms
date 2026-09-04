<div class="grid grid-flow-row gap-5 {{ $gridClass }}">
    @foreach ($posts as $post)
        @include('bladethemev1::components.posts.card')
    @endforeach
</div>
