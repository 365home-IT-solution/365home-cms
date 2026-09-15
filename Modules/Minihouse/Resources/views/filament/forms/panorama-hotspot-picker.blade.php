{{--
    Bấm trực tiếp lên ảnh 360° (dạng equirectangular, tỉ lệ 2:1) để đặt điểm nóng — KHÔNG cần tự tính
    yaw/pitch bằng tay. Vì ảnh equirectangular ánh xạ TUYẾN TÍNH theo trục ngang/dọc (đúng định nghĩa
    của định dạng này: trục X = 360° quanh trục dọc, trục Y = 180° từ đỉnh xuống đáy), toạ độ điểm bấm
    trên ảnh phẳng suy ra được yaw/pitch bằng công thức đơn giản, không cần load cả trình xem 360° nặng
    vào form quản trị — Pannellum ở trang xem công khai sẽ hiểu đúng cặp yaw/pitch này.
--}}
@php
    $statePath = $getStatePath();
    $yawPath = \Illuminate\Support\Str::replaceLast('picker', 'yaw', $statePath);
    $pitchPath = \Illuminate\Support\Str::replaceLast('picker', 'pitch', $statePath);
@endphp

<div
    x-data="{ marker: null }"
    class="mh-hotspot-picker"
    style="max-width: 640px;"
>
    @if ($imageUrl)
        <div style="position: relative; display: inline-block; width: 100%;">
            <img
                src="{{ $imageUrl }}"
                style="width: 100%; display: block; border-radius: 8px; cursor: crosshair; user-select: none;"
                x-on:click="
                    const rect = $el.getBoundingClientRect();
                    const relX = ($event.clientX - rect.left) / rect.width;
                    const relY = ($event.clientY - rect.top) / rect.height;
                    const yaw = Math.round((relX * 360 - 180) * 10) / 10;
                    const pitch = Math.round((90 - relY * 180) * 10) / 10;
                    marker = { left: (relX * 100) + '%', top: (relY * 100) + '%' };
                    $wire.set('{{ $yawPath }}', yaw);
                    $wire.set('{{ $pitchPath }}', pitch);
                "
            >
            <div
                x-show="marker"
                x-bind:style="marker ? ('left:' + marker.left + '; top:' + marker.top) : ''"
                style="position: absolute; width: 14px; height: 14px; margin-left: -7px; margin-top: -7px; border-radius: 50%; background: #f43f5e; border: 2px solid #fff; box-shadow: 0 0 0 1px rgba(0,0,0,0.3); pointer-events: none;"
            ></div>
        </div>
        <p class="fi-fo-field-wrp-helper-text" style="font-size: 12px; color: rgb(107 114 128); margin-top: 4px;">
            Bấm đúng vị trí muốn đặt điểm nóng trên ảnh — góc yaw/pitch bên dưới sẽ tự điền (vẫn có thể sửa tay để tinh chỉnh).
        </p>
    @else
        <p class="fi-fo-field-wrp-helper-text" style="font-size: 12px; color: rgb(107 114 128);">
            Lưu điểm 360° này lại (có ảnh) trước đã, sau đó mở lại để chọn điểm nóng trực tiếp trên ảnh.
        </p>
    @endif
</div>
