<?php

namespace Modules\Minihouse\App\Filament\Resources\UserResource\Tables;

use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;

class UserTable
{
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // Mobile (< md): CHỈ hiện đúng 1 dòng gọn tự vẽ (xem file blade) — chữ viết tắt tên +
                // họ tên/email bên trái, vai trò/SĐT bên phải, đủ để lướt nhanh không cần cuộn ngang.
                // DÙNG ViewColumn (Filament\Tables\Columns\ViewColumn) — KHÔNG PHẢI Tables\Columns\
                // Layout\View — vì Layout\* là loại component KHÁC hẳn (Table::hasColumnsLayout()
                // bật true), đổi HẲN cách bảng render ẢNH HƯỞNG CẢ DESKTOP dù chỉ định nghĩa 1 cột
                // layout (đã thử và gặp lỗi này ở TenantTable, phải revert). ViewColumn vẫn là Column
                // bình thường nên bảng luôn ở đúng chế độ <table> cổ điển, hiddenFrom/visibleFrom
                // hoạt động đúng như các cột khác bên dưới.
                ViewColumn::make('mobile_card')
                    ->label('')
                    ->view('minihouse::filament.tables.user-mobile-row')
                    ->hiddenFrom('md')
                    // Style nội tuyến ép co cột (xem giải thích đầy đủ ở TenantTable::table()) —
                    // class Tailwind (max-w-0/w-full) không có tác dụng vì chưa từng build vào CSS.
                    // 220px (rộng hơn các bảng khác vì bảng này KHÔNG có ô chọn nhiều/checkbox).
                    ->extraCellAttributes(['style' => 'max-width: 220px; width: 100%; overflow: hidden;']),

                TextColumn::make('fullname')->label('Họ tên')->searchable()->sortable()->visibleFrom('md'),
                TextColumn::make('email')->label('Email')->searchable()->sortable()->visibleFrom('md'),
                TextColumn::make('phone')->label('Điện thoại')->searchable()->visibleFrom('md'),
                TextColumn::make('roles.name')->label('Vai trò')->badge()->visibleFrom('md'),
                TextColumn::make('minihouseBuildings.name')->label('Toà nhà quản lý')->badge()->placeholder('Tất cả')->visibleFrom('md'),
                TextColumn::make('created_at')->label('Ngày tạo')->dateTime('d/m/Y')->sortable()->visibleFrom('md'),
            ])
            ->actions([
                // Ẩn chữ nhãn, chỉ giữ icon, RIÊNG dưới 768px — xem _mobile-action-styles.blade.php.
                EditAction::make()->extraAttributes(['class' => 'mh-row-action']),
                DeleteAction::make()->extraAttributes(['class' => 'mh-row-action']),
            ])
            ->header(fn () => view('minihouse::filament.tables._mobile-action-styles'))
            ->defaultSort('created_at', 'desc')
            ->searchable();
    }
}
