<div class="grid grid-flow-row gap-4 {{ $gridClass }}">
    @foreach ($posts as $post)
        @include('bladethemev1::components.posts.card')
    @endforeach
    <style>
        /* Định nghĩa lớp hover-lift */
        .hover-lift {
            transition: transform 0.3s ease-in-out;
        }

        .hover-lift:hover {
            transform: translateY(-0.25rem);
        }
    </style>
</div>
