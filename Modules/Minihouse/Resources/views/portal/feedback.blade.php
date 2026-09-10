@extends('minihouse::portal.layout')

@section('title', 'Gửi phản hồi - Portal khách thuê')

@section('content')
    <h1 class="text-xl font-semibold text-gray-900">Gửi phản hồi</h1>
    <p class="mt-1 text-sm text-gray-500">
        @if ($activeContract?->room)
            Phòng {{ $activeContract->room->code }} — báo sự cố hoặc góp ý, chủ nhà sẽ xem và phản hồi lại ngay trong mục "Thông báo".
        @else
            Báo sự cố hoặc góp ý, chủ nhà sẽ xem và phản hồi lại ngay trong mục "Thông báo".
        @endif
    </p>

    <div class="mt-4 rounded-xl bg-white p-4 shadow-sm border border-gray-100">
        <form method="POST" action="{{ route('minihouse.portal.feedback.store') }}" class="space-y-4">
            @csrf

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Mức độ hài lòng</label>
                <div id="star-rating" class="flex gap-1">
                    @for ($i = 1; $i <= 5; $i++)
                        <label class="cursor-pointer">
                            <input type="radio" name="rating" value="{{ $i }}" class="hidden star-input" {{ (int) old('rating', 5) === $i ? 'checked' : '' }}>
                            <span class="star text-3xl text-yellow-400 select-none">{{ $i <= (int) old('rating', 5) ? '★' : '☆' }}</span>
                        </label>
                    @endfor
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Nội dung</label>
                <textarea name="content" rows="5" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-gray-900/10" placeholder="Mô tả sự cố hoặc góp ý của bạn...">{{ old('content') }}</textarea>
            </div>

            <button type="submit" class="w-full rounded-lg bg-gray-900 text-white text-sm font-medium py-2.5 hover:bg-gray-800 transition">
                Gửi phản hồi
            </button>
        </form>
    </div>

    <script>
        const starInputs = document.querySelectorAll('#star-rating .star-input');
        const starLabels = document.querySelectorAll('#star-rating .star');

        starInputs.forEach((input, i) => {
            input.addEventListener('change', () => {
                starLabels.forEach((label, idx) => {
                    label.textContent = idx <= i ? '★' : '☆';
                });
            });
        });
    </script>
@endsection
