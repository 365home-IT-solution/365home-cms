{{-- Tab "Theo đối tác": chọn Tất cả đối tác hoặc một đối tác để xem doanh thu (cùng bộ lọc kỳ với Tổng quan). --}}
<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px;">
    <select wire:model.live="statsPartnerId" style="font-size:0.85rem;padding:6px 10px;border-radius:8px;border:1px solid #e5e7eb;min-width:280px;">
        <option value="">Tất cả đối tác</option>
        @foreach ($this->statsPartnerOptions() as $id => $name)
            <option value="{{ $id }}">{{ $name }}</option>
        @endforeach
    </select>
</div>
