{{-- Lối vào "Thuê xe" — chỉ render khi công tắc ở CMS (Thuê xe → Cài đặt thuê xe) đang BẬT; tắt thì không có gì được in ra.
     Cùng markup/cỡ chữ với partner-button và các menu item khác. --}}
@if (\Modules\VehicleRental\App\Support\VehicleRentalFeature::clientEnabled())
    <li class="relative text-[18px] menu-group">
        <a href="{{ route('vehicle-rental.page') }}"
           class="main-menu-item w-full flex items-center justify-between text-md font-medium pl-[20px] h-[40px] transition-all duration-300 ease-in-out relative {{ request()->routeIs('vehicle-rental.page') ? 'menu-active' : '' }}">
            <span class="flex-grow text-left">Thuê xe</span>
        </a>
    </li>
@endif
