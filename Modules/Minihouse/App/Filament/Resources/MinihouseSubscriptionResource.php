<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources;

use App\Models\Partner;
use App\Models\PartnerSubscription as Sub;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Minihouse\App\Filament\Resources\MinihouseSubscriptionResource\Pages;
use Modules\Minihouse\App\Support\HomestayBridge;

// Super Admin: xem gói/hạn của từng đối tác MiniHouse, đổi hạn, gia hạn tay, huỷ/khoá.
class MinihouseSubscriptionResource extends Resource
{
    protected static ?string $model = Sub::class;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Gói của đối tác';

    protected static ?string $modelLabel = 'Gói của đối tác';

    protected static ?string $pluralModelLabel = 'Gói của đối tác';

    protected static ?string $slug = 'partner-subscriptions';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['partner:id,name,legal_name,partner_type', 'plan:id,name'])
            ->where('partner_id', '!=', HomestayBridge::PARTNER_ID)
            ->whereHas('partner', fn ($p) => $p->where('partner_type', Partner::TYPE_MINIHOUSE));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()->columns(2)->schema([
                Placeholder::make('partner_name')->label('Đối tác')->content(fn (?Sub $record) => $record?->partner?->legal_name ?: $record?->partner?->name),
                Select::make('plan_id')->label('Gói')->required()
                    ->options(fn () => SubscriptionPlan::query()->where('partner_type', Partner::TYPE_MINIHOUSE)->orderBy('sort_order')->pluck('name', 'id')),
                DateTimePicker::make('expires_at')->label('Hết hạn lúc')->seconds(false)->helperText('Để trống = không giới hạn thời gian.'),
                Toggle::make('is_trial')->label('Đang dùng thử'),
                Toggle::make('auto_renew')->label('Tự động gia hạn (tạo link thanh toán trước hạn)'),
                Select::make('status')->label('Trạng thái')->options([Sub::STATUS_TRIAL => 'Dùng thử/hoạt động', Sub::STATUS_ACTIVE => 'Hoạt động', Sub::STATUS_CANCELLED => 'Huỷ / khoá thủ công'])
                    ->helperText('"Huỷ / khoá" chặn mọi tính năng ngay cả khi chưa hết hạn.')->required(),
                Textarea::make('note')->label('Ghi chú')->rows(2)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('expires_at')
            ->columns([
                // Không đặt tên cột kiểu quan hệ: dùng state() + tìm kiếm tự viết.
                TextColumn::make('partner_name')->label('Đối tác')
                    ->state(fn (Sub $r) => $r->partner?->legal_name ?: $r->partner?->name)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('partner', fn ($p) => $p->where('name', 'like', "%{$search}%")->orWhere('legal_name', 'like', "%{$search}%"))),
                TextColumn::make('plan_name')->label('Gói')->state(fn (Sub $r) => $r->plan?->name),
                TextColumn::make('state')->label('Trạng thái')->badge()
                    ->state(fn (Sub $r) => Sub::STATES[$r->state()])
                    ->color(fn (Sub $r) => match ($r->state()) {
                        Sub::STATE_ACTIVE => 'success', Sub::STATE_TRIAL => 'info', Sub::STATE_EXPIRED => 'danger', default => 'gray',
                    }),
                TextColumn::make('expires_at')->label('Hết hạn')->dateTime('d/m/Y H:i')->placeholder('Không giới hạn')->sortable()
                    ->description(fn (Sub $r) => $r->expires_at ? ($r->isExpired() ? 'Đã hết hạn' : 'Còn ' . $r->daysLeft() . ' ngày') : null),
                IconColumn::make('auto_renew')->label('Tự gia hạn')->boolean(),
            ])
            ->filters([
                SelectFilter::make('state')->label('Trạng thái')->options(Sub::STATES)
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        Sub::STATE_EXPIRED   => $query->where('status', '!=', Sub::STATUS_CANCELLED)->whereNotNull('expires_at')->where('expires_at', '<', now()),
                        Sub::STATE_CANCELLED => $query->where('status', Sub::STATUS_CANCELLED),
                        Sub::STATE_TRIAL     => $query->where('status', '!=', Sub::STATUS_CANCELLED)->where('is_trial', true)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now())),
                        Sub::STATE_ACTIVE    => $query->where('status', '!=', Sub::STATUS_CANCELLED)->where('is_trial', false)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', now())),
                        default              => $query,
                    }),
            ])
            ->actions([
                Action::make('extend')->label('Gia hạn')->icon('heroicon-o-arrow-path')->color('success')
                    ->form([TextInput::make('months')->label('Số tháng gia hạn')->numeric()->minValue(1)->maxValue(60)->default(1)->required()])
                    ->action(function (Sub $record, array $data) {
                        $sub = app(SubscriptionService::class)->extend($record, (int) $data['months']);
                        Notification::make()->title('Đã gia hạn đến ' . $sub->expires_at->format('d/m/Y'))->success()->send();
                    }),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMinihouseSubscriptions::route('/'),
            'edit'  => Pages\EditMinihouseSubscription::route('/{record}/edit'),
        ];
    }
}
