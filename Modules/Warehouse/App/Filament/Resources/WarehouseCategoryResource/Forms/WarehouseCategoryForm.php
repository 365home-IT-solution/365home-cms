<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseCategoryResource\Forms;

use App\Models\Partner;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;

class WarehouseCategoryForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            self::partnerInput(),

            TextInput::make('name')
                ->label('Tên nhóm')
                ->placeholder('VD: Buồng phòng, Đồ uống, Văn phòng phẩm')
                ->required()
                ->maxLength(150),
        ]);
    }

    // Chỉ super_admin thấy — tài khoản thường thì partner_id tự động lấy theo tài khoản đang đăng
    // nhập (BelongsToPartner::creating()). Không chọn thì bản ghi lưu partner_id RỖNG, không đối
    // tác nào thấy được — cùng pattern với WarehouseStockInForm::partnerInput(). Nhóm vật tư DÙNG
    // CHUNG cho mọi chi nhánh của đối tác — không có field chọn chi nhánh riêng.
    private static function partnerInput(): Select
    {
        return Select::make('partner_id')
            ->label('Đối tác sở hữu')
            ->options(fn () => Partner::withTrashed()
                ->orderBy('name')
                ->get()
                ->mapWithKeys(fn (Partner $partner) => [
                    $partner->id => $partner->name . ($partner->trashed() ? ' (đã xoá)' : ''),
                ])
                ->all())
            ->getOptionLabelUsing(fn ($value) => $value
                ? Partner::withTrashed()->find($value)?->name
                : null)
            ->dehydrated()
            ->searchable()
            ->preload()
            ->required()
            ->visible(fn () => auth()->user()?->isSuperAdmin() ?? false)
            ->helperText('Bắt buộc chọn — nếu không, nhóm vật tư sẽ không hiện với bất kỳ tài khoản đối tác nào.');
    }
}
