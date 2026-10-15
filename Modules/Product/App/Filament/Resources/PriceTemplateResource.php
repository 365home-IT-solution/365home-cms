<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources;

use App\Models\User;
use App\Services\PriceTemplateService;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Product\App\Filament\Resources\PriceTemplateResource\Pages;
use Modules\Product\App\Models\PriceTemplate;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\TimeSlot;

/**
 * BẢNG GIÁ MẪU — bộ khung giờ + giá có sẵn để chọn khi tạo bảng giá (cùng nghiệp vụ với
 * /api/admin/price-templates, xem App\Services\PriceTemplateService). Mẫu hệ thống (partner_id NULL)
 * đối tác chỉ xem/áp; muốn sửa thì bấm "Sửa thành bản riêng" để tách bản của đối tác.
 * KHÁC PriceBoardResource (bảng giá theo lịch).
 */
class PriceTemplateResource extends Resource
{
    protected static ?string $model = PriceTemplate::class;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static ?string $navigationGroup = 'Quản lý';
    // Ngay sau "Hệ thống giá" (-3) và "Bảng giá" (-2).
    protected static ?int $navigationSort = -1;

    public static function getNavigationLabel(): string
    {
        return 'Bảng giá mẫu';
    }

    public static function getModelLabel(): string
    {
        return 'Bảng giá mẫu';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Bảng giá mẫu';
    }

    // Ai vào được "Hệ thống giá" (view_any_book) thì dùng được bảng giá mẫu — cùng là thiết lập giá
    // khung giờ của phòng.
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('view_any_book') ?? false);
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny() && static::isEditableBy($record, auth()->user());
    }

    public static function canDelete($record): bool
    {
        return static::canEdit($record);
    }

    public static function isEditableBy(PriceTemplate $template, ?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->seesAllPartners()) {
            return true;
        }

        return $template->partner_id !== null && $template->partner_id === $user->partner_id;
    }

    /** Đối tác thấy mẫu hệ thống + mẫu của mình; mẫu hệ thống đã tách bản riêng thì ẩn đi. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->withCount('slots');
        $user  = auth()->user();

        if (! $user instanceof User || $user->seesAllPartners()) {
            return $query;
        }

        $forkedIds = PriceTemplate::where('partner_id', $user->partner_id)
            ->whereNotNull('source_template_id')
            ->pluck('source_template_id');

        return $query->usableBy($user->partner_id)->whereNotIn('id', $forkedIds);
    }

    /** Khung giờ dùng được cho mẫu: mẫu hệ thống chỉ dùng khung giờ hệ thống, mẫu đối tác dùng thêm
     *  khung giờ riêng của đối tác đó. */
    private static function timeSlotOptions(?PriceTemplate $record): array
    {
        $user      = auth()->user();
        $partnerId = $record
            ? $record->partner_id
            : (($user instanceof User && ! $user->seesAllPartners()) ? $user->partner_id : null);

        return TimeSlot::usableBy($partnerId)
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'date'))
            ->orderBy('start_time')
            ->get()
            ->mapWithKeys(fn (TimeSlot $t) => [$t->id => ($t->label ?: "{$t->start_time} - {$t->end_time}") . ($t->partner_id ? ' · riêng' : '')])
            ->all();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()->schema([
                TextInput::make('name')
                    ->label('Tên mẫu')
                    ->required()
                    ->maxLength(255),
                TextInput::make('sort_order')
                    ->label('Thứ tự')
                    ->numeric()
                    ->minValue(0)
                    ->default(0),
                Textarea::make('note')
                    ->label('Ghi chú')
                    ->rows(2)
                    ->maxLength(2000)
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label('Đang dùng')
                    ->default(true),
            ])->columns(2),

            Section::make('Khung giờ và giá')
                ->description('Thứ tự kéo thả = thứ tự hiển thị. Thiếu khung giờ thì tạo thêm khi thiết lập giá phòng ở "Hệ thống giá".')
                ->schema([
                    Repeater::make('slots')
                        ->hiddenLabel()
                        ->relationship('slots')
                        ->orderColumn('sort_order')
                        ->minItems(1)
                        ->required()
                        ->defaultItems(1)
                        ->addActionLabel('Thêm khung giờ')
                        ->schema([
                            Select::make('timeslot_id')
                                ->label('Khung giờ')
                                // Trong Repeater, $record là DÒNG khung giờ (PriceTemplateSlot), không phải bảng
                                // giá mẫu — lấy bảng giá mẫu từ trang (Edit có record, Create thì null).
                                ->options(fn ($livewire) => static::timeSlotOptions(
                                    ($livewire->record ?? null) instanceof PriceTemplate ? $livewire->record : null
                                ))
                                ->searchable()
                                ->required()
                                ->distinct()
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems()
                                ->columnSpan(2),
                            TextInput::make('price')
                                ->label('Giá')
                                ->numeric()
                                ->minValue(0)
                                ->suffix('₫'),
                            Toggle::make('over_night')
                                ->label('Qua đêm')
                                ->inline(false),
                            TextInput::make('checkin')
                                ->label('Giờ nhận riêng')
                                ->placeholder('HH:MM')
                                ->rules(['nullable', 'date_format:H:i']),
                            TextInput::make('checkout')
                                ->label('Giờ trả riêng')
                                ->placeholder('HH:MM')
                                ->rules(['nullable', 'date_format:H:i']),
                        ])
                        ->columns(6),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Tên mẫu')
                    ->searchable()
                    ->description(fn (PriceTemplate $record) => $record->note),
                TextColumn::make('kind')
                    ->label('Loại')
                    ->badge()
                    ->state(fn (PriceTemplate $record) => $record->isSystem() ? 'Mẫu hệ thống' : 'Riêng')
                    ->color(fn (PriceTemplate $record) => $record->isSystem() ? 'gray' : 'success'),
                TextColumn::make('slots_count')
                    ->label('Số khung giờ')
                    ->badge()
                    ->color('info'),
                ToggleColumn::make('is_active')
                    ->label('Đang dùng')
                    ->disabled(fn (PriceTemplate $record) => ! static::isEditableBy($record, auth()->user())),
                TextColumn::make('updated_at')
                    ->label('Cập nhật')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('scope')
                    ->label('Loại')
                    ->options(['system' => 'Mẫu hệ thống', 'mine' => 'Riêng'])
                    ->query(fn (Builder $q, array $data) => match ($data['value'] ?? null) {
                        'system' => $q->whereNull('partner_id'),
                        'mine'   => $q->whereNotNull('partner_id'),
                        default  => $q,
                    }),
            ])
            ->defaultSort('sort_order')
            ->actions([
                static::applyAction(),
                EditAction::make(),
                Action::make('fork')
                    ->label('Sửa thành bản riêng')
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->visible(function (PriceTemplate $record) {
                        $user = auth()->user();

                        return ! static::isEditableBy($record, $user) && filled($user?->partner_id);
                    })
                    ->requiresConfirmation()
                    ->modalDescription('Mẫu hệ thống giữ nguyên. Bạn sẽ sửa trên một bản sao riêng của đối tác mình.')
                    ->action(function (PriceTemplate $record, $livewire) {
                        $fork = app(PriceTemplateService::class)->forkFor($record, auth()->user()->partner_id);

                        $livewire->redirect(static::getUrl('edit', ['record' => $fork]));
                    }),
                DeleteAction::make(),
            ]);
    }

    /** Dùng mẫu = TẠO BẢNG GIÁ (price_boards) cho chi nhánh + phòng đã chọn; giá phòng đổi theo hiệu lực
     *  của bảng giá, mẫu không ghi thẳng xuống phòng. */
    private static function applyAction(): Action
    {
        return Action::make('create_board')
            ->label('Tạo bảng giá từ mẫu')
            ->icon('heroicon-o-arrow-down-on-square-stack')
            ->color('primary')
            ->modalHeading(fn (PriceTemplate $record) => "Tạo bảng giá từ \"{$record->name}\"")
            ->modalSubmitActionLabel('Tạo bảng giá')
            ->form(fn (PriceTemplate $record) => [
                TextInput::make('name')->label('Tên bảng giá')->default($record->name)->required()->maxLength(255),
                Select::make('branch_id')
                    ->label('Chi nhánh')
                    ->options(fn () => \Modules\Book\App\Filament\Resources\PriceBoardResource\Forms\PriceBoardForm::branchOptions())
                    ->searchable()
                    ->required()
                    ->live(),
                Select::make('product_ids')
                    ->label('Phòng (theo khung giờ)')
                    ->multiple()
                    ->required()
                    ->searchable()
                    ->options(fn (\Filament\Forms\Get $get) => filled($get('branch_id'))
                        ? Product::query()
                            ->where('styles', 1)
                            ->whereHas('categories', fn ($q) => $q->whereIn('categories.id', \Modules\Product\App\Models\PriceBoard::branchCategoryIds((int) $get('branch_id'))))
                            ->orderBy('name')->pluck('name', 'id')->all()
                        : []),
                \Filament\Forms\Components\DatePicker::make('start_date')->label('Ngày bắt đầu')->native(false)->displayFormat('d/m/Y'),
                \Filament\Forms\Components\DatePicker::make('end_date')->label('Ngày kết thúc')->native(false)->displayFormat('d/m/Y')->afterOrEqual('start_date'),
                Toggle::make('is_active')
                    ->label('Kích hoạt ngay')
                    ->helperText('Bật = ghi đè giá các phòng đã chọn ngay (nếu hôm nay nằm trong khoảng ngày). Tắt = chỉ lưu bảng giá.')
                    ->default(false),
            ])
            ->action(function (PriceTemplate $record, array $data) {
                try {
                    $board = app(PriceTemplateService::class)->createBoard($record, auth()->user(), $data);
                } catch (\RuntimeException $e) {
                    Notification::make()->title('Không tạo được bảng giá')->body($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Đã tạo bảng giá "' . $board->name . '" cho ' . $board->items->count() . ' phòng')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPriceTemplates::route('/'),
            'create' => Pages\CreatePriceTemplate::route('/create'),
            'edit'   => Pages\EditPriceTemplate::route('/{record}/edit'),
        ];
    }
}
