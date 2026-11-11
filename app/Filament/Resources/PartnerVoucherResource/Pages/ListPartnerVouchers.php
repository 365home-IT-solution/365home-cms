<?php

declare(strict_types=1);

namespace App\Filament\Resources\PartnerVoucherResource\Pages;

use App\Filament\Resources\PartnerVoucherResource;
use App\Models\VoucherBlock;
use App\Settings\VoucherScopeSettings;
use App\Support\VoucherScope;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class ListPartnerVouchers extends ListRecords
{
    protected static string $resource = PartnerVoucherResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        $settings = app(VoucherScopeSettings::class);
        $lines    = [];

        foreach ([VoucherScope::CHECKIN => 'Quà điểm danh', VoucherScope::MINIGAME => 'Quà trò chơi & giới thiệu'] as $scope => $label) {
            $partners = count((array) $settings->{"{$scope}_partner_ids"});
            $branches = count((array) $settings->{"{$scope}_category_ids"});
            $lines[]  = VoucherScope::isConfigured($scope)
                ? "<b>{$label}</b>: chỉ dùng được ở {$partners} đối tác" . ($branches ? " + {$branches} chi nhánh" : '') . ' đã chọn.'
                : "<b>{$label}</b>: <span style=\"color:#dc2626\">chưa chọn đối tác nào → đang dùng được ở MỌI đối tác.</span>";
        }

        return new HtmlString('Bấm vào đối tác để thiết lập: ① chi nhánh → ② loại voucher khách dùng được → ③ đợt ngừng nhận (ngày lễ, cuối tuần…).<br>'
            . implode(' · ', $lines));
    }

    public function getTabs(): array
    {
        return [
            'all'    => Tab::make('Tất cả'),
            'paused' => Tab::make('Có đợt ngừng nhận')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('id', VoucherBlock::query()->where('all_days', false)->where('is_active', true)->select('partner_id'))),
            'limited' => Tab::make('Không nhận một số loại')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('id', VoucherBlock::query()->where('all_days', true)->where('is_active', true)->select('partner_id'))),
        ];
    }
}
