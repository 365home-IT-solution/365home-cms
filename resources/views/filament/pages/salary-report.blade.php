<x-filament-panels::page>
    <div style="border:1px solid #e5e7eb;border-radius:12px;padding:16px 20px;margin-bottom:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <span style="font-size:0.85rem;color:#6b7280;">Tổng lương phải trả (theo bộ lọc hiện tại)</span>
        <span style="font-size:1.3rem;font-weight:800;color:#10b981;">{{ $this->getTotalPayroll() }}</span>
    </div>

    {{ $this->table }}
</x-filament-panels::page>
