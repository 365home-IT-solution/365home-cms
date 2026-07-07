                <!-- Summary của book.blade -->
                <div class="book-pricing-card book-pricing-summary flex flex-col items-stretch mt-5 gap-4">
                    <div class="w-full text-left font-semibold">

                        <!-- Giá cơ bản -->
                        <p class="text-base text-primary mb-2 flex items-center gap-1">
                            Giá cơ bản:
                            <span class="font-bold" x-text="totalOriginalPrice.toLocaleString() + ' đ'"></span>
                        </p>

                        <!-- Chi tiết phụ thu -->
                        <template x-if="totalIncreaseAmount > 0">
                            <div class="ml-4 mb-2">
                                <p class="text-sm text-orange-600 font-semibold flex items-center gap-1">
                                    Phụ thu:
                                </p>
                                <template x-for="(promo, name) in promotionSummary.increases" :key="name">
                                    <p class="text-xs text-orange-500 ml-4">
                                        • <span x-text="name"></span>:
                                        <span x-text="'+' + promo.total.toLocaleString() + ' đ'"></span>
                                        <span class="text-gray-500" x-text="'(' + promo.count + ' khung giờ)'"></span>
                                    </p>
                                </template>
                                <p class="text-sm text-orange-600 font-bold ml-4 mt-1 border-t border-orange-200 pt-1">
                                    Tổng phụ thu:
                                    <span x-text="'+' + totalIncreaseAmount.toLocaleString() + ' đ'"></span>
                                </p>
                            </div>
                        </template>

                        <!-- Chi tiết khuyến mãi (CHỈ hiển thị nếu KHÔNG phải full booking) -->
                        <template x-if="totalPromoDiscount > 0 && !hasFullDayBooking">
                            <div class="ml-4 mb-2">
                                <p class="text-sm text-primary font-semibold flex items-center gap-1">
                                    Khuyến mãi:
                                </p>
                                <template x-for="(promo, name) in promotionSummary.discounts" :key="name">
                                    <p class="text-xs text-primary ml-4">
                                        • <span x-text="name"></span>:
                                        <span x-text="'-' + promo.total.toLocaleString() + ' đ'"></span>
                                        <span class="text-gray-500" x-text="'(' + promo.count + ' khung giờ)'"></span>
                                    </p>
                                </template>
                                <p class="text-sm text-primary font-bold ml-4 mt-1 border-t border-primary/30 pt-1">
                                    Tổng khuyến mãi:
                                    <span x-text="'-' + totalPromoDiscount.toLocaleString() + ' đ'"></span>
                                </p>
                            </div>
                        </template>

                        <!-- Giảm giá book nhiều giờ (CHỈ khi KHÔNG phải full booking) -->
                        <template x-if="discount > 0 && !hasFullDayBooking">
                            <p class="text-sm text-primary font-semibold ml-4 mb-2 flex items-center gap-1">
                                Giảm giá đặt <span x-text="selectedSlots.length" class="mx-1"></span> khung giờ
                                (<span x-text="(discountRate * 100).toFixed(0) + '%'"></span>):
                                <span x-text="'-' + discount.toLocaleString() + ' đ'"></span>
                            </p>
                        </template>

                        <!-- Quà tặng khi đặt 2 khung giờ liên tiếp -->
                        <template x-if="selectedSlots.length >= 2 && !hasFullDayBooking">
                            <div class="ml-4 mb-2 mt-1 flex items-center gap-2 bg-amber-50 border border-amber-300 rounded-lg px-3 py-2">
                                <p class="text-sm text-amber-700 font-semibold">
                                    Bạn được tặng: <span class="text-amber-800">2 Nước Ngọt + 1 Snack</span>
                                    <span class="block text-xs font-normal text-amber-600 mt-0.5">Nhận tại quầy sau khi thanh toán</span>
                                </p>
                            </div>
                        </template>

                        <!-- Giảm giá Full booking -->
                        <template x-if="fullBookingDiscount > 0">
                            <p class="text-sm text-primary font-bold ml-4 mb-2 flex items-center gap-1">
                                Giảm giá đặt Full phòng:
                                <span x-text="'-' + fullBookingDiscount.toLocaleString() + ' đ'"></span>
                            </p>
                        </template>

                        <template x-if="hasFullDayBooking">
                            <div class="ml-4 mb-2 bg-primary/5 p-3 rounded border border-primary/30">
                                <p class="text-sm text-primary font-semibold mb-1 flex items-center gap-1">
                                    Khuyến mãi:
                                </p>
                                <p class="text-xs text-primary ml-4">
                                    • Giảm giá khi đặt full khung giờ trong ngày:
                                    <span class="font-bold" x-text="'-' + fullBookingDiscount.toLocaleString() + ' đ'"></span>
                                </p>
                            </div>
                        </template>

                        <!-- Tổng tiền -->
                        <div class="mt-3 pt-3 border-t-2 border-primary">
                            <p class="text-xl text-primary font-bold flex items-center gap-2">
                                Tổng tiền tạm tính:
                                <span class="text-2xl" x-text="totalAfterAllDiscounts.toLocaleString() + ' đ'"></span>
                            </p>
                        </div>

                        <!-- Ghi chú -->
                        <div class="mt-2 text-xs text-gray-600 italic">
                            <template x-if="!hasFullDayBooking">
                                <div class="flex flex-col gap-1.5">
                                    <p class="flex items-center gap-1">
                                        Giảm thêm 5% khi đặt 2 khung giờ, 10% khi đặt 3+ khung giờ
                                    </p>
                                    <span class="rainbow-border-btn rainbow-shimmer self-start inline-flex items-center gap-1.5 not-italic">
                                        Tặng 2 nước ngọt và 1 snack khi đặt từ 2+ khung giờ
                                    </span>
                                </div>
                            </template>
                            <template x-if="hasFullDayBooking">
                                <div>
                                    <p class="text-primary font-semibold flex items-center gap-1">
                                        Đã áp dụng ưu đãi đặc biệt cho Full phòng!
                                    </p>
                                    <p class="text-orange-600 text-xs mt-1 flex items-center gap-1">
                                        Các khuyến mãi giảm giá khác không áp dụng khi đặt full phòng
                                    </p>
                                </div>
                            </template>
                        </div>

                    </div>

                    <!-- Nút đặt phòng -->
                    <div class="w-full text-right" x-show="selectedRoomIsActive === true || selectedRoomId === null">
                        <button @click="$wire.saveAndRedirect(selectedSlots)"
                                    :disabled="selectedSlots.length === 0"
                                    class="book-cta-btn">
                                Đặt phòng ngay
                            </button>
                    </div>
                </div>
