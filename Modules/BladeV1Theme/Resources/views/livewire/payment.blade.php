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
    <style>
        .glow-effect {
            position: relative;
            overflow: hidden;
        }

        .glow-effect::before {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg,
            transparent,
            rgba(255, 255, 255, 0.8),
            transparent
            );
            transform: translateX(-100%);
            animation: glowingEffect 3s infinite;
        }

        @keyframes glowingEffect {
            0% {
                transform: translateX(-100%);
            }
            100% {
                transform: translateX(100%);
            }
        }

        .text-gradient {
            background: linear-gradient(-45deg, #ee7752, #e73c7e, #23a6d5, #23d5ab);
            background-size: 400% 400%;
            animation: gradient 15s ease infinite;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        @keyframes gradient {
            0% {
                background-position: 0% 50%;
            }
            50% {
                background-position: 100% 50%;
            }
            100% {
                background-position: 0% 50%;
            }
        }
    </style>
</div>