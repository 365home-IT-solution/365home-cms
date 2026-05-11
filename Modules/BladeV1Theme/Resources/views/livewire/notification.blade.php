<div>
    <div x-data="{ 
            show: @entangle('showMessage'),
            message: @entangle('message'),
            type: @entangle('type')
        }"
         x-show="show"
         x-transition:enter="transition ease-out duration-500"
         x-transition:enter-start="opacity-0 transform scale-75 rotate-3"
         x-transition:enter-end="opacity-100 transform scale-100 rotate-0"
         x-transition:leave="transition ease-in duration-300"
         x-transition:leave-start="opacity-100 transform scale-100 rotate-0"
         x-transition:leave-end="opacity-0 transform scale-75 rotate-3"
         @hideNotification.window="show = false"
         class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-60 backdrop-blur-sm"
         style="display: none;">

        <!-- Modal Container với glassmorphism effect -->
        <div class="relative bg-white/95 backdrop-blur-md rounded-2xl shadow-2xl p-8 mx-4 max-w-lg w-full border border-white/20"
             style="box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(255, 255, 255, 0.1);">

            <!-- Animated Background Gradient -->
            <div class="absolute inset-0 rounded-2xl overflow-hidden">
                <div x-show="type === 'success'" class="absolute inset-0 bg-gradient-to-br from-green-400/10 via-emerald-400/5 to-teal-400/10 animate-pulse"></div>
                <div x-show="type === 'error'" class="absolute inset-0 bg-gradient-to-br from-red-400/10 via-pink-400/5 to-rose-400/10 animate-pulse"></div>
                <div x-show="type === 'warning'" class="absolute inset-0 bg-gradient-to-br from-yellow-400/10 via-amber-400/5 to-orange-400/10 animate-pulse"></div>
                <div x-show="type === 'info'" class="absolute inset-0 bg-gradient-to-br from-blue-400/10 via-cyan-400/5 to-indigo-400/10 animate-pulse"></div>
            </div>

            <!-- Close Button (X) -->
            <button @click="show = false"
                    class="absolute top-4 right-4 p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-full transition-all duration-200 group">
                <svg class="w-5 h-5 group-hover:rotate-90 transition-transform duration-300" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                </svg>
            </button>

            <!-- Header với Icon Animation -->
            <div class="flex items-center mb-6 relative z-10">
                <!-- Success Icon -->
                <div x-show="type === 'success'" class="flex items-center justify-center w-16 h-16 bg-gradient-to-r from-green-500 to-emerald-500 rounded-2xl mr-4 shadow-lg transform hover:scale-105 transition-transform duration-300">
                    <svg class="w-8 h-8 text-white animate-bounce" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                    </svg>
                </div>

                <!-- Error Icon -->
                <div x-show="type === 'error'" class="flex items-center justify-center w-16 h-16 bg-gradient-to-r from-red-500 to-pink-500 rounded-2xl mr-4 shadow-lg transform hover:scale-105 transition-transform duration-300">
                    <svg class="w-8 h-8 text-white animate-pulse" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"></path>
                    </svg>
                </div>

                <!-- Warning Icon -->
                <div x-show="type === 'warning'" class="flex items-center justify-center w-16 h-16 bg-gradient-to-r from-yellow-500 to-orange-500 rounded-2xl mr-4 shadow-lg transform hover:scale-105 transition-transform duration-300">
                    <svg class="w-8 h-8 text-white animate-bounce" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                    </svg>
                </div>

                <!-- Info Icon -->
                <div x-show="type === 'info'" class="flex items-center justify-center w-16 h-16 bg-gradient-to-r from-blue-500 to-cyan-500 rounded-2xl mr-4 shadow-lg transform hover:scale-105 transition-transform duration-300">
                    <svg class="w-8 h-8 text-white animate-pulse" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path>
                    </svg>
                </div>

                <!-- Title với gradient text -->
                <div class="flex-1">
                    <h3 class="text-2xl font-bold bg-gradient-to-r bg-clip-text text-transparent mb-1"
                        :class="{
                            'from-green-600 to-emerald-600': type === 'success',
                            'from-red-600 to-pink-600': type === 'error',
                            'from-yellow-600 to-orange-600': type === 'warning',
                            'from-blue-600 to-cyan-600': type === 'info'
                        }">
                        <span x-show="type === 'success'">Thành công!</span>
                        <span x-show="type === 'error'">Lỗi!</span>
                        <span x-show="type === 'warning'">Cảnh báo!</span>
                        <span x-show="type === 'info'">Thông tin</span>
                    </h3>
                    <p class="text-sm text-gray-500 font-medium">
                        <span x-show="type === 'success'">Hành động đã được thực hiện thành công</span>
                        <span x-show="type === 'error'">Đã xảy ra lỗi, vui lòng thử lại</span>
                        <span x-show="type === 'warning'">Vui lòng kiểm tra thông tin</span>
                        <span x-show="type === 'info'">Thông tin quan trọng</span>
                    </p>
                </div>
            </div>

            <!-- Message Content với typography đẹp -->
            <div class="relative z-10 mb-8">
                <div class="bg-gray-50/50 backdrop-blur-sm rounded-xl p-4 border border-gray-100">
                    <p class="text-gray-700 text-base leading-relaxed font-medium" x-text="message"></p>
                </div>
            </div>

            <!-- Action Buttons với hover effects -->
            <div class="flex justify-end space-x-3 relative z-10">
                <button @click="show = false"
                        class="group px-6 py-3 text-sm font-semibold text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-xl transition-all duration-200 transform hover:scale-105 hover:shadow-lg">
                    <span class="group-hover:translate-x-0.5 transition-transform duration-200 inline-block">Đóng</span>
                </button>
                <button @click="show = false"
                        class="group px-6 py-3 text-sm font-semibold text-white rounded-xl transition-all duration-200 transform hover:scale-105 hover:shadow-xl"
                        :class="{
                            'bg-gradient-to-r from-green-500 to-emerald-500 hover:from-green-600 hover:to-emerald-600': type === 'success',
                            'bg-gradient-to-r from-red-500 to-pink-500 hover:from-red-600 hover:to-pink-600': type === 'error',
                            'bg-gradient-to-r from-yellow-500 to-orange-500 hover:from-yellow-600 hover:to-orange-600': type === 'warning',
                            'bg-gradient-to-r from-blue-500 to-cyan-500 hover:from-blue-600 hover:to-cyan-600': type === 'info'
                        }">
                    <span class="group-hover:translate-x-0.5 transition-transform duration-200 inline-block">Xác nhận</span>
                    <svg class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform duration-200 inline-block" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10.293 3.293a1 1 0 011.414 0l6 6a1 1 0 010 1.414l-6 6a1 1 0 01-1.414-1.414L14.586 11H3a1 1 0 110-2h11.586l-4.293-4.293a1 1 0 010-1.414z" clip-rule="evenodd"></path>
                    </svg>
                </button>
            </div>

            <!-- Decorative elements -->
            <div class="absolute top-0 left-0 w-full h-2 rounded-t-2xl overflow-hidden">
                <div class="w-full h-full bg-gradient-to-r animate-pulse"
                     :class="{
                         'from-green-400 via-emerald-400 to-teal-400': type === 'success',
                         'from-red-400 via-pink-400 to-rose-400': type === 'error',
                         'from-yellow-400 via-amber-400 to-orange-400': type === 'warning',
                         'from-blue-400 via-cyan-400 to-indigo-400': type === 'info'
                     }"></div>
            </div>
        </div>
    </div>
</div>