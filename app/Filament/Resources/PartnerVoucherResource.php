<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PartnerVoucherResource\Pages;
use App\Models\Partner;
use App\Models\VoucherBlock;
use App\Services\PartnerVoucherPolicy;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Modules\Product\App\Models\Product;

// "Cấu hình web" › "Voucher theo đối tác": mỗi đối tác 1 trang — ① áp dụng ở chi nhánh nào, ② khách được dùng loại voucher
// nào (mọi ngày), ③ các đợt ngừng nhận voucher (ngày lễ, cuối tuần…), kèm xem trước. Gộp "Nơi nhận voucher quà tặng"
// (App\Settings\VoucherScopeSettings) và lịch ngừng nhận (voucher_blocks) — xem App\Services\PartnerVoucherPolicy. Chỉ Super Admin.
class PartnerVoucherResource extends Resource
{
    protected static ?string $model = Partner::class;

    protected static ?string $slug = 'voucher-theo-doi-tac';

    protected static ?string $navigationIcon   = 'heroicon-o-ticket';
    protected static ?string $navigationGroup  = 'Cấu hình web';
    protected static ?string $navigationLabel  = 'Voucher theo đối tác';
    protected static ?string $modelLabel       = 'đối tác';
    protected static ?string $pluralModelLabel = 'Voucher theo đối tác';
    protected static ?int    $navigationSort   = 93;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canViewAny(): bool
    {
        return self::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return self::canAccess();
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    /** Đối tác homestay đã có phòng (hoặc đã có lịch voucher). Super Admin đang "chuyển đổi đối tác" chỉ thấy đối tác đó. */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return Partner::query()->withoutGlobalScopes()
            ->whereNull('deleted_at') // withoutGlobalScopes() bỏ luôn SoftDeletes — không hiện đối tác đã xoá
            ->where('partner_type', '!=', Partner::TYPE_MINIHOUSE)
            ->where(fn ($q) => $q
                ->whereIn('id', Product::withoutGlobalScopes()->whereNull('deleted_at')->whereNotNull('partner_id')->select('partner_id'))
                ->orWhereIn('id', VoucherBlock::query()->select('partner_id')))
            ->when($user && ! $user->seesAllPartners(), fn ($q) => $q->whereKey($user->partner_id ?? '-'));
    }

    public static function table(Table $table): Table
    {
        $o = fn (Partner $p) => app(PartnerVoucherPolicy::class)->overview($p);

        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Đối tác')
                    ->weight('semibold')
                    ->state(fn (Partner $p) => $p->common_name ?: $p->name)
                    ->description(fn (Partner $p) => $o($p)['branches'] . ' chi nhánh · ' . $o($p)['rooms'] . ' phòng')
                    ->searchable(['name', 'common_name']),

                TextColumn::make('accept')
                    ->label('Voucher khách dùng được')
                    ->state(function (Partner $p) use ($o) {
                        $n = count($o($p)['accept']);

                        return match (true) {
                            $n === count(VoucherBlock::GROUPS) => 'Tất cả loại voucher',
                            $n === 0                           => 'Không nhận voucher nào',
                            default                            => "{$n}/" . count(VoucherBlock::GROUPS) . ' loại',
                        };
                    })
                    ->badge()
                    ->color(fn (Partner $p) => match (count($o($p)['accept'])) {
                        count(VoucherBlock::GROUPS) => 'success',
                        0                           => 'danger',
                        default                     => 'warning',
                    })
                    ->description(function (Partner $p) use ($o) {
                        $missing = array_diff(array_keys(VoucherBlock::GROUPS), $o($p)['accept']);

                        return $missing && count($missing) < count(VoucherBlock::GROUPS)
                            ? 'Không nhận: ' . collect($missing)->map(fn ($g) => VoucherBlock::GROUPS[$g])->implode(', ')
                            : null;
                    })
                    ->wrap(),

                TextColumn::make('upcoming')
                    ->label('Đợt ngừng nhận')
                    ->state(fn (Partner $p) => $o($p)['upcoming'][0] ?? '—')
                    ->description(fn (Partner $p) => count($o($p)['upcoming']) > 1 ? '+' . (count($o($p)['upcoming']) - 1) . ' đợt khác' : null)
                    ->wrap(),

                TextColumn::make('today')
                    ->label('Hôm nay')
                    ->badge()
                    ->state(fn (Partner $p) => $o($p)['today'][1])
                    ->color(fn (Partner $p) => $o($p)['today'][0]),
            ])
            ->defaultSort('name')
            ->recordUrl(fn (Partner $p) => self::getUrl('manage', ['record' => $p]))
            ->actions([
                Action::make('quickAccept')
                    ->label('Loại voucher')
                    ->icon('heroicon-o-ticket')
                    ->color('gray')
                    ->modalHeading(fn (Partner $p) => 'Voucher khách dùng được tại ' . ($p->common_name ?: $p->name))
                    ->modalDescription('Áp dụng MỌI NGÀY. Bỏ tick = khách không dùng được loại đó khi đặt phòng ở đối tác này. Đợt ngừng nhận (ngày lễ…) giữ nguyên.')
                    ->modalSubmitActionLabel('Lưu')
                    ->fillForm(fn (Partner $p) => ['groups' => app(PartnerVoucherPolicy::class)->load($p)['accept']])
                    ->form([self::groupsField(required: false)])
                    ->action(fn (Partner $p, array $data) => self::applyAccept(collect([$p]), (array) ($data['groups'] ?? []), 'set')),
                Action::make('manage')
                    ->label('Thiết lập')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->url(fn (Partner $p) => self::getUrl('manage', ['record' => $p])),
            ])
            ->bulkActions([
                BulkAction::make('enableAll')
                    ->label('Cho dùng tất cả voucher')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Cho khách dùng TẤT CẢ loại voucher (mọi ngày)?')
                    ->modalDescription('Tick lại cả 5 loại ở bước ② cho các đối tác đã chọn. Đợt ngừng nhận (ngày lễ…) giữ nguyên.')
                    ->action(fn (Collection $records) => self::applyAccept($records, array_keys(VoucherBlock::GROUPS), 'set'))
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('changeGroups')
                    ->label('Chọn / bỏ chọn loại voucher')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->modalHeading('Đổi loại voucher khách dùng được (mọi ngày)')
                    ->modalDescription('Áp cho tất cả đối tác đã chọn. Loại không nằm trong danh sách dưới đây giữ nguyên như cũ ở từng đối tác.')
                    ->form([
                        Radio::make('mode')
                            ->label('Muốn')
                            ->options([
                                'enable'  => 'CHO khách dùng các loại dưới đây',
                                'disable' => 'KHÔNG cho khách dùng các loại dưới đây',
                            ])
                            ->default('disable')
                            ->required(),
                        self::groupsField(required: true),
                    ])
                    ->action(fn (Collection $records, array $data) => self::applyAccept($records, (array) $data['groups'], $data['mode']))
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('disableAll')
                    ->label('Ngừng mọi voucher')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Khách sẽ KHÔNG dùng được voucher nào?')
                    ->modalDescription('Bỏ tick cả 5 loại ở bước ② cho các đối tác đã chọn — khách đặt phòng ở đây không dùng được bất kỳ voucher nào, mọi ngày.')
                    ->modalSubmitActionLabel('Vẫn ngừng')
                    ->action(fn (Collection $records) => self::applyAccept($records, [], 'set'))
                    ->deselectRecordsAfterCompletion(),
            ])
            ->paginated([25, 50, 'all'])
            ->emptyStateHeading('Chưa có đối tác nào có phòng');
    }

    private static function groupsField(bool $required): CheckboxList
    {
        return CheckboxList::make('groups')
            ->label('Loại voucher')
            ->options(VoucherBlock::GROUPS)
            ->descriptions(VoucherBlock::GROUP_HINTS)
            ->bulkToggleable() // "Chọn tất cả" / "Bỏ chọn tất cả"
            ->required($required)
            ->validationMessages(['required' => 'Chọn ít nhất 1 loại voucher.']);
    }

    /** Đổi bước ② cho nhiều đối tác; đối tác nào không lưu được (VD đối tác duy nhất nhận quà điểm danh) thì báo riêng. */
    private static function applyAccept(iterable $partners, array $groups, string $mode): void
    {
        $policy = app(PartnerVoucherPolicy::class);
        $done   = 0;
        $failed = [];

        foreach ($partners as $partner) {
            try {
                $policy->updateAccept($partner, $groups, $mode, auth()->id());
                $done++;
            } catch (ValidationException $e) {
                $failed[] = '• ' . ($partner->common_name ?: $partner->name) . ': ' . collect($e->errors())->flatten()->first();
            }
        }
        PartnerVoucherPolicy::flushOverview();

        if ($done > 0) {
            Notification::make()->success()->title("Đã cập nhật {$done} đối tác")->send();
        }
        if ($failed !== []) {
            Notification::make()->danger()->persistent()
                ->title(count($failed) . ' đối tác chưa đổi được')
                ->body(implode("\n", $failed))
                ->send();
        }
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPartnerVouchers::route('/'),
            'manage' => Pages\ManagePartnerVoucher::route('/{record}'),
        ];
    }
}
