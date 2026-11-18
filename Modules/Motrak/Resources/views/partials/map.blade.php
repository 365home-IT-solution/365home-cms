{{--
    Bản đồ GPS dùng chung. Tham số:
      $markers      : danh sách marker (xem public/vendor/motrak/motrak-map.js)
      $polyline     : (tuỳ chọn) mảng [lat, lng] vẽ đường đi
      $height       : (tuỳ chọn) chiều cao px, mặc định 480
      $wireProperty : (tuỳ chọn) tên thuộc tính Livewire chứa danh sách marker để tự cập nhật khi component làm mới (VD 'markers')
--}}
@php
    $config = [
        'markers'      => array_values($markers ?? []),
        'polyline'     => $polyline ?? [],
        'height'       => $height ?? 480,
        'wireProperty' => $wireProperty ?? null,
    ];
@endphp

<div wire:ignore x-data="motrakMap(@js($config))" class="rounded-xl overflow-hidden ring-1 ring-gray-950/10 dark:ring-white/20" style="position: relative; z-index: 0;">
    <div x-ref="map" style="width: 100%;"></div>
</div>
