{{-- HEADER --}}
<div class="ta-header">
    <div>
        <div class="ta-sub-label">— 01 / Tổng quan đơn đặt phòng</div>
        <h2 class="ta-title">Thống kê <em>BOOKING</em><br/><span id="ta-period-range">{{ $dateRange }}</span></h2>
    </div>
    <div class="ta-header-right">
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
        @if(auth()->user()?->isSuperAdmin())
        <button class="ta-branch-toggle" id="ta-branch-toggle" onclick="window.taBranchToggle()" title="Xem doanh thu theo chi nhánh">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="3"/><circle cx="5" cy="19" r="3"/><circle cx="19" cy="19" r="3"/><path d="M12 8v4M8.5 17l3.5-5M15.5 17l-3.5-5"/></svg>
            Chi nhánh
            <svg class="ta-branch-caret" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M6 9l6 6 6-6"/></svg>
        </button>
        @endif
    </div>
</div>

{{-- Branch filter panel (chỉ render cho super_admin) --}}
@if(auth()->user()?->isSuperAdmin())
<div class="ta-branch-panel" id="ta-branch-panel" style="display:none;">
    <div class="ta-branch-panel-head">
        <div class="ta-branch-panel-title">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="5" r="3"/><circle cx="5" cy="19" r="3"/><circle cx="19" cy="19" r="3"/><path d="M12 8v4M8.5 17l3.5-5M15.5 17l-3.5-5"/></svg>
            Lọc theo chi nhánh &mdash; <span id="ta-branch-range">{{ $dateRange }}</span>
        </div>
        <button class="ta-branch-close" onclick="window.taBranchToggle()" title="Đóng">&#x2715;</button>
    </div>
    <div class="ta-branch-list-wrap">
        {{-- Tất cả --}}
        <div class="ta-branch-item active" data-branch-id="" onclick="window.taBranchSelect(this)">
            <div class="ta-branch-item-left">
                <span class="ta-branch-dot" style="background:#6b7280"></span>
                <span class="ta-branch-item-name">Tất cả chi nhánh</span>
            </div>
            <div class="ta-branch-item-stats" id="ta-bfi-all">
                <span class="ta-bfi-count">—</span>
                <span class="ta-bfi-rev">—</span>
            </div>
        </div>
        @php $branchColors = ['#10b981','#3b82f6','#8b5cf6','#f59e0b','#ef4444','#06b6d4','#f97316','#84cc16','#ec4899','#6366f1']; @endphp
        @foreach($branches as $i => $branch)
        <div class="ta-branch-item" data-branch-id="{{ $branch->id }}" onclick="window.taBranchSelect(this)">
            <div class="ta-branch-item-left">
                <span class="ta-branch-dot" style="background:{{ $branchColors[$i % count($branchColors)] }}"></span>
                <span class="ta-branch-item-name">{{ $branch->name }}</span>
            </div>
            <div class="ta-branch-item-stats" id="ta-bfi-{{ $branch->id }}">
                <span class="ta-bfi-count">—</span>
                <span class="ta-bfi-rev">—</span>
            </div>
        </div>
        @endforeach
    </div>
    <div class="ta-branch-panel-foot">
        <span id="ta-branch-foot-hint">Click vào chi nhánh để lọc tất cả số liệu ở trên</span>
    </div>
</div>
@endif

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
