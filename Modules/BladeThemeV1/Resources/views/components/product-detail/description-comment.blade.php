        <style>
            #default-tab [role="tab"][aria-selected="true"] {
                color: var(--color-primary);
                border-bottom: 2px solid var(--color-primary);
                background-color: transparent;
            }
            #default-tab [role="tab"][aria-selected="false"] {
                color: #6b7280;
                border-bottom: 2px solid transparent;
            }
        </style>
        <div class="mb-6 rounded-lg overflow-hidden border-b border-gray-200">
            <ul class="flex text-sm font-semibold text-center bg-white" id="default-tab" role="tablist">
                <li class="flex-1" role="presentation">
                    <button
                            class="w-full p-4 transition-all duration-300 text-gray-700 hover:bg-gray-100 focus:outline-none rounded-tl-lg"
                            id="dashboard-tab" data-tabs-target="#dashboard" type="button" role="tab"
                            aria-controls="dashboard" aria-selected="true">
                        <span class="relative inline-block">
                            Mô tả sản phẩm
                            <span
                                    class="absolute bottom-0 left-0 w-full h-0.5 bg-primary-500 transform scale-x-0 transition-transform duration-300 group-hover:scale-x-100"></span>
                        </span>
                    </button>
                </li>
                <li class="flex-1" role="presentation">
                    <button
                            class="w-full p-4 transition-all duration-300 text-gray-700 hover:bg-gray-100 focus:outline-none rounded-tr-lg"
                            id="profile-tab" data-tabs-target="#profile" type="button" role="tab"
                            aria-controls="profile" aria-selected="false">
                        <span class="relative inline-block">
                            Bình luận
                            <span
                                    class="absolute bottom-0 left-0 w-full h-0.5 bg-primary-500 transform scale-x-0 transition-transform duration-300 group-hover:scale-x-100"></span>
                        </span>
                    </button>
                </li>
            </ul>
        </div>

        <div id="default-tab-content">
            <div class="p-4 rounded-lg bg-white" id="dashboard" role="tabpanel" aria-labelledby="dashboard-tab">
                <div class="mb-6 product-content">
                    {!! $product->description ?? 'Mô tả dài sản phẩm không có' !!}
                </div>
            </div>
            <div class="hidden p-4 bg-white rounded-lg" id="profile" role="tabpanel"
                 aria-labelledby="profile-tab">
                @livewire('bladethemev1::comment', [
                'commentableId' => $product->id,
                'commentableType' => get_class($product),
                ])
            </div>
        </div>