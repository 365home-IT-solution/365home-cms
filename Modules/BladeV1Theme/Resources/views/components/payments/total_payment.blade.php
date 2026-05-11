<div>
    <div class="bg-white rounded-xl p-6 shadow-lg sticky top-6">
        @if ($cart && count($cart) > 0)
            <div class="space-y-4">
                <div class="flex justify-between pb-4 border-b">
                    @php
                        $total = 0;
                            foreach ($cart as $item) {
                                $price = $item['discount'] > 0 ? $item['discount'] : $item['price'];
                                $total += $price * $item['quantity'];
                            }
                    @endphp
                    <span class="text-gray-600">Tổng tiền hàng</span>
                    <span class="font-semibold">{{ number_format($total, 0, ',', '.') }} đ</span>
                </div>
                <div class="flex justify-between pb-4 border-b">
                    <span class="text-gray-600">Phí vận chuyển</span>
                    <span class="text-orange-500">Chưa tính phí</span>
                </div>
                <div class="flex justify-between pb-4 border-b">
                    <span class="text-gray-600">Giảm giá</span>
                    <span class="text-green-500">- {{ number_format($discount, 0, ',', '.') }} đ</span>
                </div>
                <div class="flex justify-between pt-4">
                    <span class="font-bold text-lg">Tổng thanh toán</span>
                    <span
                            class="font-bold text-xl text-blue-900">{{ number_format($finalTotal, 0, ',', '.') }} đ</span>
                </div>
            </div>

            <!-- Phương thức thanh toán -->
            <div class="mt-6">
                <div class="flex items-center gap-2 p-4 border rounded-lg">
                    <input wire:model="paymentMethod" value="cod" type="radio" id="cod" checked/>
                    <label for="cod" class="flex items-center gap-2">
                        <i class="fas fa-money-bill-wave text-green-500"></i>
                        Thanh toán khi nhận hàng
                    </label>
                </div>
            </div>
        @endif

        @if ($cart && count($cart) > 0)
            <div class="flex flex-row gap-2 items-center content-center">
                <input id="terms" wire:model="acceptTerms" type="checkbox"
                       class="w-5 h-5 border border-gray-300 rounded bg-gray-50 focus:ring-3 focus:ring-primary cursor-pointer">
                <p class="text-sm text-gray-500 text-start mt-4">
                    Tôi đã đọc và đồng ý với <a href="/dieu-khoan-thanh-toan" class="text-blue-500">điều khoản thanh toán</a> của shop
                </p>
            </div>
        @endif

        @error('acceptTerms')
        <span class="text-red-500 text-sm mb-4">{{ $message }}</span>
        @enderror

        <!-- Nút đặt hàng -->
        @if ($cart && count($cart) > 0)
            <x-bladethemev1::buttons.button :style="'1'" type="submit" class="w-full mt-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/>
                </svg>
                Đặt hàng
            </x-bladethemev1::buttons.button>
        @else
            <div class="w-full max-w-md mx-auto mt-3">
                <div
                        class="flex items-center gap-2 px-4 py-3 bg-red-50 border border-red-200 text-red-600 rounded-lg animate-in fade-in slide-in-from-top-1 duration-300">
                    <svg xmlns="http://www.w3.org/2000/svg"
                         class="w-5 h-5"
                         fill="none"
                         viewBox="0 0 24 24"
                         stroke="currentColor"
                         stroke-width="2">
                        <path stroke-linecap="round"
                              stroke-linejoin="round"
                              d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <span class="text-sm font-medium">Vui lòng chọn sản phẩm để đặt hàng!</span>
                </div>
            </div>
        @endif


    </div>
</div>