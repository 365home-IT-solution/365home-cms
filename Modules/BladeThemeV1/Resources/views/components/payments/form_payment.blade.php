<div>
    @if ($cart && count($cart) > 0)
        <div class="bg-white rounded-xl p-8 shadow-lg">
            <h2 class="text-xl font-bold text-blue-900 mb-6">Thông tin người nhận</h2>

            <div class="space-y-6">
                <div id="contactForm" class="grid grid-cols-1 md:grid-cols-3 gap-6">

                    <div class="relative">
                        <label for="name" class="block text-gray-700 mb-2 font-medium">Họ và tên</label>
                        <input id="name" type="text" wire:model="buyerName" name="name" placeholder="Nhập họ và tên của bạn..."
                               class="w-full p-3 border rounded-lg outline-none transition-all duration-200 pr-10
                                        focus:ring-black peer"/>
                        @error('buyerName')
                        <span class="text-red-500 text-sm mt-1 ml-1">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="relative">
                        <label for="phone" class="block text-gray-700 mb-2 font-medium">Số điện thoại</label>
                        <input id="phone" type="tel" wire:model="buyerPhone" name="phone" placeholder="Nhập số điện thoại của bạn..."
                               class="w-full p-3 border rounded-lg outline-none transition-all duration-200 pr-10
                                        focus:ring-black peer"/>
                        @error('buyerPhone')
                        <span class="text-red-500 text-sm mt-1">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="relative">
                        <label for="email" class="block text-gray-700 mb-2 font-medium">Email</label>
                        <input id="email" type="email" wire:model="buyerEmail" name="email" placeholder="Nhập email của bạn..."
                               class="w-full p-3 border rounded-lg outline-none transition-all duration-200 pr-10
                                       focus:ring-black peer"/>

                        @error('buyerEmail')
                        <span class="text-red-500 text-sm mt-1 ml-1">{{ $message }}</span>
                        @enderror
                    </div>
                </div>

                <div>
                    <label class="block text-gray-700 mb-2">Lời nhắn cho Shop</label>
                    <textarea wire:model="description" rows="4" placeholder="Để lại lời nhắn..."
                              class="w-full p-3 border rounded-lg focus:ring-2 focus:ring-black outline-none"></textarea>
                </div>

                <div>
                    <h2 class="text-xl font-bold text-blue-900 mb-6">Địa chỉ nhận hàng</h2>
                    <div id="contactForm" class="grid grid-cols-1 md:grid-cols-3 gap-4">

                        <div class="relative">
                            <select wire:model.live="provinceId" id="province"
                                    class="w-full p-3 border rounded-lg outline-none transition-all duration-200 pr-10 focus:ring-black">
                                <option value="">Chọn Tỉnh/Thành Phố</option>
                                <div wire:loading wire:target="provinceId">Đang tải...</div>
                                @forelse ($provinces as $province)
                                    <option value="{{ $province['ProvinceID'] }}">{{ $province['ProvinceName'] }}</option>
                                @empty
                                    <option value="" disabled>Không có tỉnh nào khả dụng</option>
                                @endforelse
                            </select>

                            @error('provinceId')
                            <span class="text-red-500 text-sm mt-1 ml-1">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="relative">
                            <select wire:model.live="districtId" id="district"
                                    class="w-full p-3 border rounded-lg outline-none transition-all duration-200 pr-10 focus:ring-2 focus:ring-black appearance-none"
                                    {{ empty($districts) ? 'disabled' : '' }}>
                                <option value="">Chọn Quận/Huyện</option>
                                @forelse ($districts as $district)
                                    <option value="{{ $district['DistrictID'] }}">{{ $district['DistrictName'] }}</option>
                                @empty
                                    <option value="" disabled>Không có quận nào khả dụng</option>
                                @endforelse
                            </select>
                            <div class="px-2" wire:loading wire:target="provinceId">Đang tải
                                Quận/Huyện...
                            </div>
                            @error('districtId')
                            <span class="text-red-500 text-sm mt-1 ml-1">{{ $message }}</span>
                            @enderror
                        </div>

                        <div class="relative">
                            <select wire:model.live="wardId" id="ward"
                                    class="w-full p-3 border rounded-lg outline-none transition-all duration-200 pr-10 focus:ring-2 focus:ring-black appearance-none"
                                    {{ empty($wards) ? 'disabled' : '' }}>
                                <option value="">Chọn Phường/Xã</option>
                                @forelse ($wards as $ward)
                                    <option value="{{ $ward['WardCode'] }}">{{ $ward['WardName'] }}</option>
                                @empty
                                    <option value="" disabled>Không có phường nào khả dụng</option>
                                @endforelse
                            </select>
                            <div class="px-2" wire:loading wire:target="districtId">Đang tải
                                Phường/Xã...</div>
                            @error('wardId')
                            <span class="text-red-500 text-sm mt-1 ml-1">{{ $message }}</span>
                            @enderror
                        </div>

                    </div>
                </div>

                <div class="relative">
                    <input id="street" wire:model="buyerAddress" type="text" placeholder="Nhập tên đường, tòa nhà, số nhà..."
                           class="w-full p-3 pr-10 border rounded-lg outline-none transition-all duration-200
                                    focus:ring-2 focus:ring-black"/>

                    @error('buyerAddress')
                    <span class="text-red-500 text-sm mt-1 ml-1">{{ $message }}</span>
                    @enderror
                </div>
            </div>
        </div>
    @endif
</div>