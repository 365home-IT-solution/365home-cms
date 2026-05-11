<!-- resources/views/product-display.blade.php -->
<div class="max-w-screen-xl md:px-8 mx-auto my-8">
    <div class="relative rounded-lg overflow-hidden">
        <!-- Background pattern -->
        <div class="absolute inset-0 bg-[url('/path/to/subtle-texture.jpg')] opacity-10"></div>

        <div class="relative py-12">
            <!-- Product Title -->
            <div class="text-center mb-12">
                <h2 class="text-green-700 font-semibold text-2xl uppercase tracking-wider">Sản Phẩm Nổi Bật</h2>
            </div>

            <!-- Product Content -->
            <div class="flex flex-col md:flex-row items-center gap-8 px-6">
                <!-- Left Column - Product Image -->
                <div class="md:w-1/2 flex justify-center">
                    <img src="{{ asset('storage/banner1.png') }}" alt="Fin Fine Coffee">
                </div>

                <!-- Right Column - Product Info -->
                <div class="md:w-1/2">
                    <h3 class="text-green-700 text-2xl font-bold mb-3">FIN FINE</h3>
                    <p class="text-gray-700 mb-6">
                        Fin Fine là dòng cà phê phin được tạo nên từ 100% hạt Robusta tuyển chọn, mang đến hương vị đậm đà với vị ngọt của chocolate đen và thể chất mạnh mẽ, phù hợp cho những ai yêu thích gụ cà phê mạnh.
                    </p>

                    <!-- Product Features -->
                    <ul class="space-y-2 mb-8">
                        <li class="flex items-center gap-2">
                            <svg class="w-5 h-5 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                            </svg>
                            <span class="text-gray-700">Hương vị đậm đà</span>
                        </li>
                        <li class="flex items-center gap-2">
                            <svg class="w-5 h-5 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                            </svg>
                            <span class="text-gray-700">Vị ngọt của chocolate</span>
                        </li>
                    </ul>

                    <!-- CTA Button -->
                    <div>
                        <a href="#" class="inline-block bg-green-700 hover:bg-green-800 text-white font-medium py-2 px-6 rounded-md transition-colors">
                            Xem thêm
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>