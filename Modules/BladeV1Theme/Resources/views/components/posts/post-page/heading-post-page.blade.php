<div>
    @if (isset($image))
        <div class="relative bg-white shadow-lg overflow-hidden rounded">
            <img src="{{ asset('/storage/' . $image) }}" alt="Ảnh trang tin tức"
                 class="w-full h-32 lg:h-48 object-cover rounded">
            <h2 class="absolute top-[50%] translate-y-[-50%] text-{{ $locate ?? 'center' }} z-10 w-full md:px-8 px-4 text-white sm:text-xs md:text-xl lg:text-2xl font-bold uppercase">
                {{ $title ?? 'Trang bài viết' }}
            </h2>
            <div class="absolute inset-0 bg-black opacity-50"></div>
        </div>
    @else
        <div class="relative">
            <h2 class="text-{{ $locate ?? 'center' }} rounded-lg lg:p-3 px-2 py-2 bg-primary text-white sm:text-xs md:text-xl lg:text-2xl font-bold uppercase mt-2">
                {{ $title ?? 'Trang bài viết' }}
            </h2>
        </div>
    @endif
</div>

