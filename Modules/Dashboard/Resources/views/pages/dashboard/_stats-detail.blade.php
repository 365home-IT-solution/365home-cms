{{-- TAB "CHI TIẾT": Super Admin chọn một đối tác; chủ đối tác luôn là chính mình. Thân số liệu dùng chung với trang Quản lý › Thống kê hoa hồng. --}}
@php
    $isPlatform = $this->isStatsPlatform();
    $detail = $this->statsDetail();
    $partnerSelect = '';
    if ($isPlatform) {
        $options = $this->statsPartnerOptions();
        $html = '<select wire:model.live="statsPartnerId" style="font-size:0.85rem;padding:6px 10px;border-radius:8px;border:1px solid #e5e7eb;min-width:280px;">'
            . '<option value="">— Chọn đối tác —</option>';
        foreach ($options as $id => $name) {
            $html .= '<option value="' . e($id) . '"' . ($id === $statsPartnerId ? ' selected' : '') . '>' . e($name) . '</option>';
        }
        $partnerSelect = new \Illuminate\Support\HtmlString($html . '</select>');
    }
@endphp
@include('dashboard::pages.dashboard._stats-filter', ['extra' => $partnerSelect])

@include('filament.partials.commission-stats-body', [
    'stats' => $detail,
    'emptyMessage' => $isPlatform ? 'Chọn một đối tác ở ô bên trên (hoặc bấm vào một đối tác ở tab Các đối tác).' : 'Tài khoản chưa gắn đối tác Homestay.',
])
