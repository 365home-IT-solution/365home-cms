<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ScratchCardPrizeResource\Pages;
use App\Filament\Support\GameFormFields;
use App\Models\ScratchCardPrize;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

// Bộ giải của mini game THẺ CÀO (chỉ Super Admin, chỉ cấu hình cho app) — cùng dữ liệu với /api/admin/games/scratch-card/prizes.
// Cài đặt chung (bật/tắt, hạn cào, đơn tối thiểu…) là nút "Cài đặt thẻ cào" ở đầu danh sách.
class ScratchCardPrizeResource extends Resource
{
    protected static ?string $model = ScratchCardPrize::class;

    protected static ?string $navigationIcon   = 'heroicon-o-ticket';
    protected static ?string $navigationGroup  = 'Quản lý API';
    protected static ?string $navigationLabel  = 'Thẻ cào — Giải thưởng';
    protected static ?string $modelLabel       = 'Giải thẻ cào';
    protected static ?string $pluralModelLabel = 'Giải thẻ cào';
    protected static ?string $slug             = 'game-scratch-card-prizes';
    protected static ?int    $navigationSort   = 31;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Giải thưởng')->schema([
                Grid::make(3)->schema([
                    TextInput::make('label')->label('Tên giải')->required()->maxLength(255),

                    Select::make('type')
                        ->label('Loại giải')
                        ->options(['coupon' => 'Voucher', 'none' => 'Không trúng (chúc may mắn)'])
                        ->default('coupon')
                        ->required()
                        ->live(),

                    Select::make('tier')
                        ->label('Hạng')
                        ->options(['normal' => 'Thường', 'rare' => 'Hiếm', 'jackpot' => 'Jackpot'])
                        ->default('normal')
                        ->required(),
                ]),

                Grid::make(3)->schema(GameFormFields::coupon(required: false))
                    ->visible(fn (Get $get) => $get('type') === 'coupon'),
            ]),

            Section::make('Tỉ lệ & giới hạn')->schema([
                Grid::make(3)->schema([
                    TextInput::make('weight')
                        ->label('Trọng số')
                        ->helperText('Càng lớn càng dễ trúng so với các giải khác.')
                        ->numeric()->integer()->minValue(1)->default(1)->required(),

                    TextInput::make('daily_limit')
                        ->label('Giới hạn / ngày')
                        ->helperText('Bỏ trống = không giới hạn.')
                        ->numeric()->integer()->minValue(1)
                        ->disabled(fn (Get $get) => (bool) $get('is_fallback'))
                        ->dehydrated(fn (Get $get) => ! $get('is_fallback')),

                    TextInput::make('total_limit')
                        ->label('Giới hạn tổng')
                        ->helperText('Bỏ trống = không giới hạn.')
                        ->numeric()->integer()->minValue(1)
                        ->disabled(fn (Get $get) => (bool) $get('is_fallback'))
                        ->dehydrated(fn (Get $get) => ! $get('is_fallback')),
                ]),

                Grid::make(2)->schema([
                    Toggle::make('is_fallback')
                        ->label('Giải dự phòng')
                        ->helperText('Không giới hạn lượt; hết ngân sách các giải khác thì khách rơi về giải này. Bật thẻ cào cần có 1 giải dự phòng đang bật.')
                        ->live()
                        ->inline(false),

                    Toggle::make('is_active')->label('Đang bật')->default(true)->inline(false),
                ]),
            ]),
        ]);
    }

    // Giải dự phòng không giới hạn lượt; giải "không trúng" không mang voucher.
    public static function normalize(array $data): array
    {
        if ($data['is_fallback'] ?? false) {
            $data['daily_limit'] = $data['total_limit'] = null;
        }

        if (($data['type'] ?? null) === 'none') {
            $data['coupon_type'] = $data['coupon_value'] = $data['min_order_value'] = $data['max_discount'] = null;
        } elseif (($data['type'] ?? null) === 'coupon' && (empty($data['coupon_type']) || empty($data['coupon_value']))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['data.coupon_value' => 'Giải voucher cần loại giảm và giá trị giảm.']);
        }

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->label('Giải')->searchable(),

                TextColumn::make('tier')->label('Hạng')->badge()
                    ->formatStateUsing(fn (string $state) => ['normal' => 'Thường', 'rare' => 'Hiếm', 'jackpot' => 'Jackpot'][$state] ?? $state)
                    ->color(fn (string $state) => ['normal' => 'gray', 'rare' => 'info', 'jackpot' => 'warning'][$state] ?? 'gray'),

                TextColumn::make('coupon_value')->label('Giá trị')
                    ->formatStateUsing(fn ($state, ScratchCardPrize $r) => $r->type !== 'coupon' ? '—'
                        : ($r->coupon_type === 'percentage' ? rtrim(rtrim((string) $state, '0'), '.') . '%' : number_format((float) $state) . 'đ')),

                TextColumn::make('weight')->label('Trọng số'),
                TextColumn::make('daily_limit')->label('Giới hạn/ngày')->placeholder('Không giới hạn'),
                TextColumn::make('total_limit')->label('Giới hạn tổng')->placeholder('Không giới hạn'),
                TextColumn::make('awarded_count')->label('Đã trúng'),
                IconColumn::make('is_fallback')->label('Dự phòng')->boolean(),
                IconColumn::make('is_active')->label('Đang bật')->boolean(),
            ])
            ->defaultSort('id')
            ->actions([
                EditAction::make()->label('Sửa'),
                // Giải đã có người trúng thì chỉ tắt, không xoá (giữ lịch sử trúng thưởng).
                DeleteAction::make()->label('Xoá')
                    ->action(function (ScratchCardPrize $record): void {
                        if ($record->awarded_count > 0) {
                            $record->update(['is_active' => false]);
                            Notification::make()->title('Giải đã có người trúng nên chỉ được tắt, không xoá.')->warning()->send();

                            return;
                        }

                        $record->delete();
                        Notification::make()->title('Đã xoá giải.')->success()->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListScratchCardPrizes::route('/'),
            'create' => Pages\CreateScratchCardPrize::route('/create'),
            'edit'   => Pages\EditScratchCardPrize::route('/{record}/edit'),
        ];
    }
}
