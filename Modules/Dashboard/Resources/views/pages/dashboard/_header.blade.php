{{-- HEADER --}}
<div class="ta-header">
    <div>
        <div class="ta-sub-label">— 01 / Tổng quan đơn đặt phòng</div>
        <h2 class="ta-title">Thống kê <em>BOOKING</em><br/><span id="ta-period-range">{{ $dateRange }}</span></h2>
    </div>
    <div class="ta-tabs">
        @foreach(['today' => 'Hôm nay', 'yesterday' => 'Hôm qua', '7d' => '7 Ngày', '30d' => '30 Ngày', '90d' => '90 Ngày', 'this_month' => 'Tháng này', 'last_month' => 'Tháng trước', 'ytd' => 'Năm nay', 'last_year' => 'Năm trước'] as $val => $lbl)
            <button class="ta-tab {{ $this->period === $val ? 'active' : '' }}"
                    data-period="{{ $val }}"
                    wire:click="$set('period', '{{ $val }}')">{{ $lbl }}</button>
        @endforeach
        <button class="ta-tab ta-tab-custom {{ $this->period === 'custom' ? 'active' : '' }}"
                data-period="custom"
                wire:click="$set('period', 'custom')">Tùy chọn</button>
    </div>
</div>

{{-- Custom date range picker — chỉ hiện khi chọn "Tùy chọn" --}}
@if($this->period === 'custom')
<div class="ta-custom-range" id="ta-custom-range"
     x-data="{}"
     x-init="
         $nextTick(function() {
             var fmtYmd = function(d) {
                 return d.getFullYear() + '-'
                     + String(d.getMonth()+1).padStart(2,'0') + '-'
                     + String(d.getDate()).padStart(2,'0');
             };
             flatpickr(document.getElementById('ta-custom-start'), {
                 locale: 'vn',
                 dateFormat: 'Y-m-d',
                 altInput: true,
                 altFormat: 'd/m/Y',
                 altInputClass: 'ta-date-input',
                 maxDate: 'today',
                 defaultDate: '{{ $this->customStart ?? now()->subDays(29)->format('Y-m-d') }}',
                 onChange: function(dates) {
                     if (dates[0]) $wire.set('customStart', fmtYmd(dates[0]));
                 }
             });
             flatpickr(document.getElementById('ta-custom-end'), {
                 locale: 'vn',
                 dateFormat: 'Y-m-d',
                 altInput: true,
                 altFormat: 'd/m/Y',
                 altInputClass: 'ta-date-input',
                 maxDate: 'today',
                 defaultDate: '{{ $this->customEnd ?? now()->format('Y-m-d') }}',
                 onChange: function(dates) {
                     if (dates[0]) $wire.set('customEnd', fmtYmd(dates[0]));
                 }
             });
         });
     ">
    <span class="ta-custom-label">Từ ngày</span>
    <input type="text" id="ta-custom-start" class="ta-date-input" readonly>
    <span class="ta-custom-sep">→</span>
    <span class="ta-custom-label">đến</span>
    <input type="text" id="ta-custom-end" class="ta-date-input" readonly>
    <span class="ta-custom-note">
        % tăng/giảm so với kỳ trước cùng độ dài —
        các tab <em>7 Ngày / 30 Ngày / 90 Ngày / Năm nay</em> dùng mốc cố định, không bị ảnh hưởng bởi ô này
    </span>
</div>
@endif
