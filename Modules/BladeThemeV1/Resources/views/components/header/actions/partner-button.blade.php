{{-- Nút cố định "Làm đối tác" (không nằm trong menu CMS) → trang đăng ký hợp tác Homestay/MiniHouse.
     Cùng markup/cỡ chữ với các menu item (livewire/menu-item.blade.php): li text-[18px] + main-menu-item. --}}
<li class="relative text-[18px] menu-group">
    <a href="{{ route('partner-onboarding.page') }}"
       class="main-menu-item w-full flex items-center justify-between text-md font-medium pl-[20px] h-[40px] transition-all duration-300 ease-in-out relative {{ request()->routeIs('partner-onboarding.page') ? 'menu-active' : '' }}">
        <span class="flex-grow text-left">Làm đối tác</span>
    </a>
</li>
