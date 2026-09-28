@extends('minihouse::portal.layout')

@section('title', 'Gửi phản hồi - Portal khách thuê')

@section('content')
    <h1 class="text-xl font-bold text-gray-900 mh-heading">Gửi phản hồi</h1>
    <p class="mt-1 text-sm text-gray-500">
        @if ($activeContract?->room)
            Phòng {{ $activeContract->room->code }} — báo sự cố hoặc góp ý, chủ nhà sẽ xem và phản hồi lại ngay trong mục "Thông báo".
        @else
            Báo sự cố hoặc góp ý, chủ nhà sẽ xem và phản hồi lại ngay trong mục "Thông báo".
        @endif
    </p>

    <div class="mt-4 mh-card mh-card-pad">
        <form method="POST" action="{{ route('minihouse.portal.feedback.store') }}" class="space-y-4">
            @csrf

            <div>
                <label class="mh-field-label">Mức độ hài lòng</label>
                <div id="star-rating" class="flex gap-1">
                    @for ($i = 1; $i <= 5; $i++)
                        <label class="cursor-pointer">
                            <input type="radio" name="rating" value="{{ $i }}" class="hidden star-input" {{ (int) old('rating', 5) === $i ? 'checked' : '' }}>
                            <span class="star text-4xl select-none" style="color: #FBBF24;">{{ $i <= (int) old('rating', 5) ? '★' : '☆' }}</span>
                        </label>
                    @endfor
                </div>
            </div>

            <div>
                <label class="mh-field-label">Nội dung</label>
                <textarea name="content" rows="5" class="mh-input" placeholder="Mô tả sự cố hoặc góp ý của bạn...">{{ old('content') }}</textarea>
            </div>

            <button type="submit" class="mh-btn-primary">Gửi phản hồi</button>
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
