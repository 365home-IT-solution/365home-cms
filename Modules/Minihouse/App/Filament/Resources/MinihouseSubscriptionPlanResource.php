<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources;

use App\Models\Partner;
use App\Models\SubscriptionPlan;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Minihouse\App\Filament\Resources\MinihouseSubscriptionPlanResource\Pages;

// Super Admin: GÓI DỊCH VỤ của MiniHouse — chỉ 1 gói (giá theo THÁNG, đối tác mua 1/3/6/9/12 tháng), mở toàn bộ chức năng.
class MinihouseSubscriptionPlanResource extends Resource
{
    protected static ?string $model = SubscriptionPlan::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Gói dịch vụ';

    protected static ?string $modelLabel = 'Gói dịch vụ';

    protected static ?string $pluralModelLabel = 'Gói dịch vụ';

    protected static ?string $slug = 'subscription-plans';

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('partner_type', Partner::TYPE_MINIHOUSE);
    }

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->isSuperAdmin() && ! $record->subscriptions()->exists();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin gói')->columns(2)->schema([
                TextInput::make('name')->label('Tên gói')->required()->maxLength(255),
                TextInput::make('code')->label('Mã gói')->required()->maxLength(50)->unique(ignoreRecord: true)->alphaDash()->helperText('Mã nội bộ, không trùng.'),
                Hidden::make('partner_type')->default(Partner::TYPE_MINIHOUSE)->dehydrateStateUsing(fn () => Partner::TYPE_MINIHOUSE),
                TextInput::make('sort_order')->label('Thứ tự')->numeric()->default(0),
                Textarea::make('description')->label('Mô tả')->rows(2)->columnSpanFull(),
                TagsInput::make('support_info')->label('Thông tin hỗ trợ (hiển thị trên thẻ gói)')->placeholder('Thêm dòng…')->columnSpanFull(),
            ]),
            Section::make('Giá & thời hạn')->columns(3)->schema([
                TextInput::make('price_vnd')->label('Giá mỗi tháng (đồng)')->numeric()->minValue(0)->default(0)->suffix('đ')
                    ->helperText('Đối tác mua theo 1, 3, 6, 9, 12 tháng: số tiền = giá/tháng × số tháng.'),
                TextInput::make('period_months')->label('Số tháng mỗi kỳ')->numeric()->minValue(1)->maxValue(60)->default(1)->required(),
                TextInput::make('trial_months')->label('Dùng thử (tháng)')->numeric()->minValue(0)->maxValue(24)->default(6)
                    ->helperText('Số tháng miễn phí khi đối tác MỚI đăng ký (áp dụng khi đây là gói mặc định).'),
                Toggle::make('is_default')->label('Gói mặc định cho đối tác mới'),
                Toggle::make('is_active')->label('Đang bán / cho phép chọn')->default(true),
            ]),
            Section::make('Ưu đãi theo kỳ mua')->description('% giảm trên tổng tiền của từng kỳ. Để trống hoặc 0 = chưa giảm.')->columns(5)->schema(
                collect(config('subscription.period_options'))->map(
                    fn (int $n) => TextInput::make("period_discounts.{$n}")->label("{$n} tháng")->numeric()->minValue(0)->maxValue(100)->suffix('%')->default(0)
                )->all()
            ),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label('Gói')->searchable()->description(fn (SubscriptionPlan $r) => $r->code),
                TextColumn::make('price_vnd')->label('Giá / tháng')->formatStateUsing(fn ($state) => $state > 0 ? number_format($state) . 'đ' : 'Chưa đặt giá'),
                TextColumn::make('trial_months')->label('Dùng thử')->formatStateUsing(fn ($state) => $state . ' tháng'),
                TextColumn::make('subscriptions_count')->label('Đối tác')->counts('subscriptions'),
                IconColumn::make('is_default')->label('Mặc định')->boolean(),
                IconColumn::make('is_active')->label('Đang bán')->boolean(),
            ])
            ->actions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListMinihouseSubscriptionPlans::route('/'),
            'create' => Pages\CreateMinihouseSubscriptionPlan::route('/create'),
            'edit'   => Pages\EditMinihouseSubscriptionPlan::route('/{record}/edit'),
        ];
    }
}
