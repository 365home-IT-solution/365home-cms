<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Resources;

use Modules\Minihouse\App\Filament\Resources\MinihouseSubscriptionPaymentResource\Pages;
use App\Models\Partner;
use App\Models\SubscriptionPayment as Pay;
use App\Services\SubscriptionService;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

// Super Admin: danh sách giao dịch thanh toán phí gói (mã giao dịch, số tiền, trạng thái) + xác nhận tay khi tiền về mà
// hệ thống chưa tự ghi nhận (hoặc nhận thiếu).
class MinihouseSubscriptionPaymentResource extends Resource
{
    protected static ?string $model = Pay::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Thanh toán gói';

    protected static ?string $modelLabel = 'Thanh toán gói';

    protected static ?string $pluralModelLabel = 'Thanh toán gói dịch vụ';

    protected static ?string $slug = 'subscription-payments';

    protected static ?int $navigationSort = 5;

    public static function canViewAny(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
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
            ->whereHas('partner', fn ($p) => $p->where('partner_type', Partner::TYPE_MINIHOUSE));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Thời gian')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('transaction_code')->label('Mã giao dịch')->copyable()->searchable()->weight('bold'),
                TextColumn::make('partner_name')->label('Đối tác')
                    ->state(fn (Pay $r) => $r->partner?->legal_name ?: $r->partner?->name)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('partner', fn ($p) => $p->where('name', 'like', "%{$search}%")->orWhere('legal_name', 'like', "%{$search}%"))),
                TextColumn::make('plan_name')->label('Gói')->state(fn (Pay $r) => $r->plan?->name)->description(fn (Pay $r) => $r->months . ' tháng' . ($r->is_renewal ? ' · gia hạn' : '')),
                TextColumn::make('amount_vnd')->label('Số tiền')->formatStateUsing(fn ($state) => number_format((int) $state) . 'đ')->sortable(),
                TextColumn::make('status')->label('Trạng thái')->badge()
                    ->formatStateUsing(fn (string $state) => Pay::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Pay::STATUS_PAID => 'success', Pay::STATUS_PENDING => 'warning', default => 'gray',
                    }),
                TextColumn::make('paid_at')->label('Thanh toán lúc')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextColumn::make('extends_to')->label('Gia hạn đến')->dateTime('d/m/Y')->placeholder('—'),
                TextColumn::make('bank_reference')->label('Mã GD ngân hàng')->placeholder('—')->toggleable(),
                TextColumn::make('note')->label('Ghi chú')->wrap()->limit(60)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                // Mặc định CHỈ hiện giao dịch có tiền hoặc cần xử lý: đã thanh toán, hoặc đang chờ nhưng có ghi chú (yêu cầu gói chưa có
                // phí, trả thiếu tiền). Ẩn các giao dịch đối tác chỉ bấm chọn gói rồi huỷ/bỏ (chờ thanh toán, đã huỷ, hết hạn).
                Filter::make('attention')->label('Chỉ giao dịch có tiền / cần xử lý')->toggle()->default()
                    ->query(fn (Builder $query) => $query->where(fn ($w) => $w->where('status', Pay::STATUS_PAID)
                        ->orWhere(fn ($p) => $p->where('status', Pay::STATUS_PENDING)->whereNotNull('note')))),
                SelectFilter::make('status')->label('Trạng thái')->options(Pay::STATUSES),
            ])
            ->actions([
                Action::make('confirm')->label('Xác nhận đã nhận tiền')->icon('heroicon-o-check-badge')->color('success')
                    ->visible(fn (Pay $r) => $r->status === Pay::STATUS_PENDING)
                    ->form(fn (Pay $r) => [
                        TextInput::make('amount')->label('Số tiền đã nhận (đồng)')->numeric()->minValue(1)->required()->default($r->amount_vnd > 0 ? $r->amount_vnd : null)
                            ->helperText($r->amount_vnd > 0 ? null : 'Yêu cầu của gói chưa có phí: nhập số tiền thực nhận để ghi nhận.'),
                        TextInput::make('reference')->label('Mã giao dịch ngân hàng')->required()->maxLength(120),
                    ])
                    ->modalDescription('Dùng khi tiền đã vào tài khoản công ty nhưng hệ thống chưa tự ghi nhận. Xác nhận sẽ gia hạn/kích hoạt gói cho đối tác ngay.')
                    ->action(function (Pay $record, array $data) {
                        try {
                            $done = app(SubscriptionService::class)->markPaid($record, (int) $data['amount'], (string) $data['reference']);
                        } catch (ValidationException $e) {
                            Notification::make()->title('Không thực hiện được')->body(collect($e->errors())->flatten()->first())->danger()->send();

                            return;
                        }

                        $done
                            ? Notification::make()->title('Đã xác nhận và gia hạn gói')->success()->send()
                            : Notification::make()->title('Chưa kích hoạt')->body('Số tiền chưa đủ hoặc mã giao dịch đã được dùng.')->warning()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListMinihouseSubscriptionPayments::route('/')];
    }
}
