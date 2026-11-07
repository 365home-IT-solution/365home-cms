<?php

namespace App\Filament\Pages\Setting;

use App\Models\Partner;
use App\Settings\VoucherScopeSettings;
use App\Support\VoucherScope;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Pages\SettingsPage;
use Modules\Category\Entities\Category;

// Đối tác / chi nhánh được dùng voucher do hệ thống tự tặng (mã điểm danh, voucher mini game) — xem App\Support\VoucherScope.
// Chỉ đối tác đã đồng ý mới được chọn: voucher 365home tặng dùng ở phòng đối tác chưa đồng ý sẽ bị đối tác khiếu nại.
class ManageVoucherScopeSettings extends SettingsPage
{
    protected static string $settings = VoucherScopeSettings::class;

    protected static ?int $navigationSort = 98;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $title = 'Phạm vi voucher tự tặng';

    public static function getNavigationGroup(): ?string
    {
        return 'Cấu hình web';
    }

    public static function getNavigationLabel(): string
    {
        return 'Phạm vi voucher tự tặng';
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            $this->scopeSection(
                VoucherScope::CHECKIN,
                'Mã điểm danh',
                'Mã tặng khi khách điểm danh đủ chu kỳ (cài số ngày, giá trị mã ở Hạng thành viên → "Tự động tạo mã khuyến mãi định kỳ").',
            ),
            $this->scopeSection(
                VoucherScope::MINIGAME,
                'Voucher mini game',
                'Áp dụng cho voucher của thẻ cào, sưu tầm mảnh ghép, giới thiệu bạn bè, đổi điểm game và Caro. Giải Caro đã chọn chi nhánh riêng thì theo giải.',
            ),
        ]);
    }

    private function scopeSection(string $scope, string $heading, string $description): Forms\Components\Section
    {
        return Forms\Components\Section::make($heading)
            ->description($description . ' Chỉ áp cho mã tặng từ lúc lưu — mã đã tặng giữ nguyên.')
            ->schema([
                Forms\Components\Placeholder::make("{$scope}_warning")
                    ->hiddenLabel()
                    ->content('⚠ Chưa chọn đối tác / chi nhánh: mã vẫn được tặng nhưng dùng được ở MỌI đối tác.')
                    ->extraAttributes(['class' => 'text-danger-600 font-medium'])
                    ->visible(fn (Get $get) => empty($get("{$scope}_partner_ids")) && empty($get("{$scope}_category_ids"))),

                Forms\Components\Select::make("{$scope}_partner_ids")
                    ->label('Đối tác áp dụng')
                    ->multiple()
                    ->searchable()
                    ->live()
                    ->options(fn () => Partner::query()->withoutGlobalScopes()
                        ->where('partner_type', '!=', Partner::TYPE_MINIHOUSE)
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->helperText('Mã dùng được ở mọi phòng của các đối tác đã chọn — cả phòng chưa gắn chi nhánh và chi nhánh mở sau này.')
                    ->requiredWithout("{$scope}_category_ids")
                    ->validationMessages(['required_without' => 'Chọn ít nhất 1 đối tác hoặc 1 chi nhánh.']),

                Forms\Components\Select::make("{$scope}_category_ids")
                    ->label('Chỉ một số chi nhánh (không bắt buộc)')
                    ->multiple()
                    ->searchable()
                    ->options(fn (Get $get) => Category::query()
                        ->whereNull('parent_id')
                        ->where('category_type', 'product')
                        ->when($get("{$scope}_partner_ids"), fn ($q, $partnerIds) => $q->whereIn('partner_id', $partnerIds))
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->helperText('Chọn khi chỉ muốn áp dụng ở vài chi nhánh cụ thể (gồm cả khu vực con) — có chọn thì ô đối tác chỉ dùng để lọc danh sách này.'),
            ]);
    }

    // Select multiple trả chuỗi id; chi nhánh lưu dạng số
    protected function mutateFormDataBeforeSave(array $data): array
    {
        foreach ([VoucherScope::CHECKIN, VoucherScope::MINIGAME] as $scope) {
            $data["{$scope}_partner_ids"]  = array_values(array_map('strval', $data["{$scope}_partner_ids"] ?? []));
            $data["{$scope}_category_ids"] = array_values(array_map('intval', $data["{$scope}_category_ids"] ?? []));
        }

        return $data;
    }
}
