<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PhoneBranchBlockResource\Pages;
use App\Models\Customer;
use App\Models\PhoneBranchBlock;
use App\Services\CustomerBranchBlockService;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Category\Entities\Category;

// Chặn SĐT đặt phòng theo chi nhánh (phone_branch_blocks) — SĐT không cần có hồ sơ khách hàng. Kiểm tra lúc tạo đơn ở
// App\Services\CustomerBranchBlockService (app, web, khách vãng lai, nhân viên đặt hộ). Cùng quyền với Khách hàng.
class PhoneBranchBlockResource extends Resource
{
    protected static ?string $model = PhoneBranchBlock::class;

    protected static ?string $navigationIcon   = 'heroicon-o-phone-x-mark';
    protected static ?string $navigationGroup  = 'Quản lý';
    protected static ?string $navigationLabel  = 'Chặn SĐT đặt phòng';
    protected static ?string $modelLabel       = 'SĐT bị chặn';
    protected static ?string $pluralModelLabel = 'SĐT bị chặn đặt phòng';
    protected static ?int    $navigationSort   = 21;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_customer') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('update_customer') ?? false;
    }

    public static function canEdit($record): bool
    {
        return self::canCreate();
    }

    public static function canDelete($record): bool
    {
        return self::canCreate();
    }

    public static function canDeleteAny(): bool
    {
        return self::canCreate();
    }

    /** Form thêm chặn: mỗi item = nhiều SĐT (chọn khách có sẵn và/hoặc nhập tay) × nhiều chi nhánh. */
    public static function blockItemsSchema(): array
    {
        return [
            Repeater::make('items')
                ->label('Danh sách chặn')
                ->addActionLabel('Thêm item')
                ->defaultItems(1)
                ->minItems(1)
                ->reorderable(false)
                ->collapsible()
                ->itemLabel(fn (array $state): string => self::itemLabel($state))
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Select::make('customer_phones')
                        ->label('Chọn khách hàng')
                        ->multiple()
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Customer::query()
                            ->where(fn (Builder $q) => $q->where('phone', 'like', "%{$search}%")->orWhere('fullname', 'like', "%{$search}%"))
                            ->whereNotNull('phone')
                            ->limit(30)
                            ->get(['phone', 'fullname'])
                            ->mapWithKeys(fn (Customer $c) => [$c->phone => trim($c->phone . ' — ' . $c->fullname, ' —')])
                            ->all())
                        ->getOptionLabelsUsing(fn (array $values): array => array_combine($values, $values))
                        ->placeholder('Tìm theo tên hoặc SĐT…'),

                    TagsInput::make('phones')
                        ->label('Hoặc nhập SĐT')
                        ->placeholder('Nhập SĐT rồi Enter (dán nhiều số cách nhau dấu phẩy)')
                        ->splitKeys(['Enter', ',', ';', ' '])
                        ->requiredWithout('customer_phones')
                        ->validationMessages(['required_without' => 'Chọn khách hàng hoặc nhập ít nhất 1 SĐT.'])
                        ->nestedRecursiveRules([
                            fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                                if (! CustomerBranchBlockService::normalizePhone((string) $value)) {
                                    $fail("SĐT \"{$value}\" không hợp lệ.");
                                }
                            },
                        ]),

                    Select::make('branch_ids')
                        ->label('Chi nhánh không cho đặt phòng')
                        ->options(fn () => self::branchOptions())
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->required(),

                    Textarea::make('reason')
                        ->label('Lý do (tuỳ chọn)')
                        ->rows(1)
                        ->maxLength(500),
                ]),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['branch:id,name', 'blocker:id,fullname']))
            ->columns([
                TextColumn::make('phone')
                    ->label('Số điện thoại')
                    ->searchable()
                    ->copyable()
                    ->weight('semibold'),

                TextColumn::make('customer_name')
                    ->label('Khách hàng')
                    ->state(fn (PhoneBranchBlock $record) => self::customerNames()[$record->phone] ?? null)
                    ->placeholder('Chưa có tài khoản'),

                TextColumn::make('branch.name')
                    ->label('Chi nhánh bị chặn')
                    ->badge()
                    ->color('danger'),

                TextColumn::make('reason')
                    ->label('Lý do')
                    ->limit(60)
                    ->placeholder('—'),

                TextColumn::make('blocker.fullname')
                    ->label('Người chặn')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Ngày chặn')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('category_id')
                    ->label('Chi nhánh')
                    ->options(fn () => self::branchOptions()),
            ])
            ->actions([
                EditAction::make()
                    ->label('Sửa')
                    ->form([
                        Select::make('category_id')
                            ->label('Chi nhánh')
                            ->options(fn () => self::branchOptions())
                            ->required()
                            ->rule(fn (PhoneBranchBlock $record) => \Illuminate\Validation\Rule::unique('phone_branch_blocks', 'category_id')
                                ->where('phone', $record->phone)->ignore($record->id)),
                        Textarea::make('reason')->label('Lý do')->rows(2)->maxLength(500),
                    ]),
                DeleteAction::make()->label('Bỏ chặn')->modalHeading('Bỏ chặn SĐT ở chi nhánh này?'),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->label('Bỏ chặn đã chọn'),
                ]),
            ])
            ->emptyStateHeading('Chưa chặn SĐT nào')
            ->emptyStateDescription('Bấm "Thêm chặn SĐT" để chặn 1 hoặc nhiều SĐT đặt phòng ở chi nhánh được chọn.');
    }

    public static function getEloquentQuery(): Builder
    {
        return app(CustomerBranchBlockService::class)->phoneBlocksQuery(auth()->user());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPhoneBranchBlocks::route('/'),
        ];
    }

    /** Chi nhánh gốc trong phạm vi quyền của người đang thao tác. */
    public static function branchOptions(): array
    {
        $ids = auth()->user()?->rootProductCategoryIds() ?? [];

        return Category::whereIn('id', $ids ?: [-1])->orderBy('name')->pluck('name', 'id')->all();
    }

    private static ?array $customerNames = null;

    // SĐT chuẩn hoá → tên khách có hồ sơ trùng SĐT (1 truy vấn cho cả trang, SĐT khách lưu nhiều dạng 0…/84…/+84…).
    private static function customerNames(): array
    {
        if (self::$customerNames !== null) {
            return self::$customerNames;
        }

        $phones = self::getEloquentQuery()->distinct()->pluck('phone')->all();
        $variants = collect($phones)->flatMap(fn (string $p) => [$p, '84' . substr($p, 1), '+84' . substr($p, 1)])->all();

        return self::$customerNames = Customer::whereIn('phone', $variants ?: ['-'])->get(['phone', 'fullname'])
            ->mapWithKeys(fn (Customer $c) => [CustomerBranchBlockService::normalizePhone($c->phone) => $c->fullname ?: $c->phone])
            ->all();
    }

    private static function itemLabel(array $state): string
    {
        $phones = array_unique(array_merge((array) ($state['customer_phones'] ?? []), (array) ($state['phones'] ?? [])));
        $count  = count((array) ($state['branch_ids'] ?? []));

        return $phones
            ? implode(', ', array_slice($phones, 0, 3)) . (count($phones) > 3 ? ' +' . (count($phones) - 3) : '') . " · {$count} chi nhánh"
            : 'Item mới';
    }
}
