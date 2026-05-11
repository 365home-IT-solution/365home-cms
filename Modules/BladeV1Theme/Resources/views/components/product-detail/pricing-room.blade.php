 <div class="mb-8">
                                <h3 class="text-3xl font-bold mb-6">Bảng giá phòng</h3>
                                <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 border-2 border-dashed gap-4 bg-gray-100 p-4">
                                    @forelse ($roomTimeSlots as $slot)
                                        <div class="flex justify-start items-center gap-2">
                                            <p class="text-xl font-bold text-primary">{{ number_format($slot['price'], 0, ',', '.') }} </p>
                                            <p> đ/{{ $slot['time_range'] }}</p>
                                        </div>
                                    @empty
                                        <p>Không có thông tin giá phòng.</p>
                                    @endforelse
                                </div>
                            </div>