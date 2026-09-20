<?php

declare(strict_types=1);

namespace Modules\Invoice\App\Filament\Resources\InvoiceResource\Forms;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Modules\Invoice\App\Models\Invoice;

class InvoiceForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Đơn hàng')
                    ->schema([
                        Placeholder::make('order_code')
                            ->label('Mã đơn hàng')
                            ->content(fn (?Invoice $record) => $record?->order?->order_code ?? '—'),
                        Placeholder::make('status')
                            ->label('Trạng thái')
                            ->content(fn (?Invoice $record) => $record?->statusLabel() ?? '—'),
                    ])
                    ->columns(2),

                // Chỉ được sửa thông tin người mua khi hoá đơn còn ở bản nháp — sau khi (giai đoạn
                // sau) đã phát hành thật qua MISA, dữ liệu người mua trên hoá đơn đã ký KHÔNG được
                // sửa tự do nữa (muốn sửa phải lập hoá đơn điều chỉnh/thay thế theo đúng quy định).
                Section::make('Thông tin người mua')
                    ->schema([
                        Select::make('buyer_type')
                            ->label('Loại người mua')
                            ->options([
                                Invoice::BUYER_TYPE_INDIVIDUAL => 'Khách lẻ',
                                Invoice::BUYER_TYPE_COMPANY    => 'Công ty',
                            ])
                            ->required(),
                        TextInput::make('buyer_name')
                            ->label('Tên người mua / đơn vị')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('buyer_tax_code')
                            ->label('Mã số thuế')
                            ->maxLength(20)
                            ->visible(fn ($get) => $get('buyer_type') === Invoice::BUYER_TYPE_COMPANY),
                        TextInput::make('buyer_email')
                            ->label('Email nhận hoá đơn')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('buyer_phone')
                            ->label('Điện thoại')
                            ->maxLength(20),
                        TextInput::make('buyer_address')
                            ->label('Địa chỉ')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->disabled(fn (?Invoice $record) => $record !== null && ! $record->isDraft()),
            ]);
    }
}
