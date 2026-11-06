<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\GamePointRewardResource\Pages;
use App\Filament\Support\GameFormFields;
use App\Models\GamePointReward;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

// Danh mục quà đổi bằng điểm game (chỉ Super Admin) — cùng dữ liệu với /api/admin/games/points/rewards.
class GamePointRewardResource extends Resource
{
    protected static ?string $model = GamePointReward::class;

    protected static ?string $navigationIcon   = 'heroicon-o-gift-top';
    protected static ?string $navigationGroup  = 'Quản lý API';
    protected static ?string $navigationLabel  = 'Điểm game — Quà đổi';
    protected static ?string $modelLabel       = 'Quà đổi điểm';
    protected static ?string $pluralModelLabel = 'Quà đổi điểm';
    protected static ?string $slug             = 'game-point-rewards';
    protected static ?int    $navigationSort   = 36;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Quà')->schema([
                Grid::make(2)->schema([
                    TextInput::make('name')->label('Tên quà')->required()->maxLength(255),
                    TextInput::make('points_cost')->label('Số điểm cần đổi')->numeric()->integer()->minValue(1)->maxValue(10000000)->required(),
                ]),
                Textarea::make('description')->label('Mô tả')->rows(2)->maxLength(2000),
            ]),

            Section::make('Voucher nhận được')->schema([
                Grid::make(3)->schema(GameFormFields::coupon()),
            ]),

            Section::make('Giới hạn')->schema([
                Grid::make(4)->schema([
                    TextInput::make('stock')->label('Tổng lượt đổi')->helperText('Bỏ trống = không giới hạn.')->numeric()->integer()->minValue(1),
                    TextInput::make('per_customer_limit')->label('Mỗi khách đổi tối đa')->helperText('Bỏ trống = không giới hạn.')->numeric()->integer()->minValue(1),
                    TextInput::make('sort_order')->label('Thứ tự')->numeric()->integer()->default(0),
                    Toggle::make('is_active')->label('Đang mở đổi')->default(true)->inline(false),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Quà')->searchable(),
                TextColumn::make('points_cost')->label('Điểm')->sortable(),
                TextColumn::make('coupon_value')->label('Giá trị')
                    ->formatStateUsing(fn ($state, GamePointReward $r) => $r->coupon_type === 'percentage'
                        ? rtrim(rtrim((string) $state, '0'), '.') . '%' : number_format((float) $state) . 'đ'),
                TextColumn::make('validity_days')->label('Hạn dùng')->suffix(' ngày'),
                TextColumn::make('stock')->label('Tổng lượt')->placeholder('Không giới hạn'),
                TextColumn::make('redeemed_count')->label('Đã đổi'),
                TextColumn::make('per_customer_limit')->label('Mỗi khách')->placeholder('Không giới hạn'),
                IconColumn::make('is_active')->label('Đang mở')->boolean(),
            ])
            ->defaultSort('sort_order')
            ->actions([
                EditAction::make()->label('Sửa'),
                // Quà đã có người đổi thì chỉ tắt, không xoá (giữ lịch sử).
                DeleteAction::make()->label('Xoá')
                    ->action(function (GamePointReward $record): void {
                        if ($record->redeemed_count > 0) {
                            $record->update(['is_active' => false]);
                            Notification::make()->title('Quà đã có người đổi nên chỉ được tắt, không xoá.')->warning()->send();

                            return;
                        }

                        $record->delete();
                        Notification::make()->title('Đã xoá quà.')->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListGamePointRewards::route('/'),
            'create' => Pages\CreateGamePointReward::route('/create'),
            'edit'   => Pages\EditGamePointReward::route('/{record}/edit'),
        ];
    }
}
