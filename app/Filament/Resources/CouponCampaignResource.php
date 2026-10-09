<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CouponCampaignResource\Pages;
use App\Models\CouponPartnerParticipation;
use App\Models\Partner;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Promotion\App\Models\Coupon;

// CHIẾN DỊCH ĐỒNG TÀI TRỢ của 365home (mã funded_by = shared, không thuộc đối tác nào): đối tác chọn chi nhánh nào tham gia thì mã mới áp lên phòng của chi nhánh
// đó (Coupon::appliesToRoom()). Mặc định chưa tham gia. Cùng nghiệp vụ với API …/coupon-campaigns. Chủ đối tác chọn; Super Admin chỉ xem số chi nhánh tham gia.
class CouponCampaignResource extends Resource
{
    protected static ?string $model = Coupon::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Quản lý';

    protected static ?string $navigationLabel = 'Chiến dịch đồng tài trợ';

    protected static ?string $modelLabel = 'Chiến dịch';

    protected static ?string $pluralModelLabel = 'Chiến dịch đồng tài trợ';

    protected static ?string $slug = 'coupon-campaigns';

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        return Coupon::withoutGlobalScopes()->where('funded_by', Coupon::FUNDED_SHARED)->whereNull('partner_id')->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>', now()));
    }

    private static function partner(): ?Partner
    {
        $user = auth()->user();

        return $user?->partner_id ? Partner::find($user->partner_id) : null;
    }

    /** Chi nhánh gốc của đối tác đang đăng nhập. */
    private static function branches()
    {
        return self::partner()?->categories()->whereNull('parent_id')->orderBy('name')->get(['id', 'name']) ?? collect();
    }

    public static function table(Table $table): Table
    {
        $isSuperAdmin = auth()->user()?->isSuperAdmin() ?? false;

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label('Mã')->weight('bold')->searchable(),
                Tables\Columns\TextColumn::make('name')->label('Chiến dịch')->searchable(),
                Tables\Columns\TextColumn::make('value')->label('Giảm')->formatStateUsing(fn ($state, Coupon $record) => $record->type === 'percentage' ? rtrim(rtrim((string) $state, '0'), '.') . '%' : number_format((float) $state, 0, ',', '.') . 'đ'),
                Tables\Columns\TextColumn::make('partner_share_pct')->label('Đối tác chịu')->suffix('%')->description('365home chịu phần còn lại'),
                Tables\Columns\TextColumn::make('end_at')->label('Hết hạn')->dateTime('d/m/Y')->placeholder('Không giới hạn'),
                Tables\Columns\TextColumn::make('joined')->label($isSuperAdmin ? 'Số chi nhánh tham gia' : 'Chi nhánh của bạn đang tham gia')
                    ->state(function (Coupon $record) use ($isSuperAdmin) {
                        $query = CouponPartnerParticipation::query()->where('coupon_id', $record->id)->where('is_enabled', true);

                        if ($isSuperAdmin) {
                            return (string) $query->count();
                        }

                        $names = self::branches()->whereIn('id', $query->pluck('category_id'))->pluck('name');

                        return $names->isEmpty() ? 'Chưa tham gia' : $names->implode(', ');
                    })->wrap(),
            ])
            ->actions([
                Tables\Actions\Action::make('choose')
                    ->label('Chọn chi nhánh tham gia')->icon('heroicon-o-check-circle')
                    ->visible(fn () => ! (auth()->user()?->isSuperAdmin() ?? false) && self::partner() !== null && self::canViewAny())
                    ->modalDescription(fn (Coupon $record) => "Đối tác chịu {$record->partner_share_pct}% tiền giảm của mã {$record->code}, 365home chịu phần còn lại. Chi nhánh không chọn thì mã không áp lên phòng của chi nhánh đó.")
                    ->fillForm(fn (Coupon $record) => ['branches' => CouponPartnerParticipation::query()->where('coupon_id', $record->id)->where('is_enabled', true)->pluck('category_id')->map(fn ($id) => (int) $id)->all()])
                    ->form([Forms\Components\CheckboxList::make('branches')->label('Chi nhánh tham gia')->options(fn () => self::branches()->pluck('name', 'id')->all())->bulkToggleable()])
                    ->action(function (Coupon $record, array $data) {
                        $partner = self::partner();
                        $chosen = collect($data['branches'] ?? [])->map(fn ($id) => (int) $id);

                        foreach (self::branches() as $branch) {
                            CouponPartnerParticipation::updateOrCreate(
                                ['coupon_id' => $record->id, 'category_id' => $branch->id],
                                ['partner_id' => $partner->id, 'is_enabled' => $chosen->contains((int) $branch->id), 'decided_by' => auth()->id(), 'decided_at' => now()],
                            );
                        }

                        Notification::make()->success()->title('Đã cập nhật chi nhánh tham gia chiến dịch.')->send();
                    }),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCouponCampaigns::route('/')];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isSuperAdmin() || ($user?->partner_id && ($user->hasRole('partner') || $user->can('update_partner'))));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
