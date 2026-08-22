<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseUnitResource\Forms;

use App\Models\Partner;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;

class WarehouseUnitForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            self::partnerInput(),

            TextInput::make('name')
                ->label('Tên đơn vị tính')
                ->placeholder('VD: cái, hộp, chai, kg')
                ->required()
                ->maxLength(50),
        ]);
    }

    // Xem giải thích ở WarehouseCategoryForm::partnerInput(). Đơn vị tính DÙNG CHUNG cho mọi chi
    // nhánh của đối tác — không có field chọn chi nhánh riêng.
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
            ->helperText('Bắt buộc chọn — nếu không, đơn vị tính sẽ không hiện với bất kỳ tài khoản đối tác nào.');
    }
}
