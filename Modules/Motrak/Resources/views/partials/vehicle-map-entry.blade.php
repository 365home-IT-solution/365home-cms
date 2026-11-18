@php
    /** @var \Modules\Motrak\App\Models\MotrakVehicle $vehicle */
    $vehicle = $getRecord();
    $marker = \Modules\Motrak\App\Support\MotrakTransformer::marker($vehicle);
@endphp

@if ($marker)
    @include('motrak::partials.map', ['markers' => [$marker], 'height' => 420])
@else
    <p class="text-sm text-gray-500 dark:text-gray-400">Xe này chưa có vị trí GPS hợp lệ để hiển thị trên bản đồ.</p>
@endif
