<div class="max-w-screen-xl md:px-8 px-4 mx-auto mb-8">
    <div class="flex items-center justify-center">
        <h1 class="text-4xl font-bold text-primary p-4">
            Thanh toán
        </h1>
    </div>

    <form wire:submit.prevent="createPaymentLink" class="flex gap-8 flex-col lg:flex-row">
        @csrf
        <div class="flex-1">
            <div class="bg-white rounded-xl p-8 shadow-lg mb-8">
                <!-- Danh sách sản phẩm -->
                @include('bladethemev1::components.payments.list_product')

            </div>

            <!-- Form thông tin giao hàng -->
            @include('bladethemev1::components.payments.form_payment')
        </div>


        <!-- Phần tổng kết đơn hàng -->
        <div class="w-full lg:w-96">
            @include('bladethemev1::components.payments.total_payment')
        </div>
    </form>
</div>