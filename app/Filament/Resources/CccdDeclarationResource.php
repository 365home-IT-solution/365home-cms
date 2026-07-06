<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CccdDeclarationResource\Pages;
use App\Models\CccdDeclaration;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Forms\Components\DatePicker;

class CccdDeclarationResource extends Resource
{
    protected static ?string $model = CccdDeclaration::class;

    protected static ?string $navigationIcon   = 'heroicon-o-identification';
    protected static ?string $navigationGroup  = 'Quản lý';
    protected static ?string $navigationLabel  = 'Khai báo lưu trú';
    protected static ?string $modelLabel       = 'Khai báo lưu trú';
    protected static ?string $pluralModelLabel = 'Khai báo lưu trú';
    protected static ?int    $navigationSort   = 30;

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin trích xuất từ CCCD')->schema([
                Grid::make(2)->schema([
                    TextInput::make('full_name')
                        ->label('Họ và tên')
                        ->maxLength(255),

                    TextInput::make('cccd_number')
                        ->label('Số CCCD')
                        ->maxLength(20),

                    TextInput::make('info')
                        ->label('Thông tin (Giới tính / Ngày sinh / Quốc tịch)')
                        ->placeholder('Nam / DD/MM/YYYY / Cộng hòa xã hội chủ nghĩa Việt Nam')
                        ->columnSpanFull()
                        ->maxLength(255),
                ]),
            ]),

            Section::make('Thông tin lưu trú')->schema([
                Grid::make(2)->schema([
                    TextInput::make('room_number')
                        ->label('Số phòng / Số căn hộ')
                        ->maxLength(255),

                    TextInput::make('reason_for_stay')
                        ->label('Lý do lưu trú')
                        ->maxLength(255),

                    DateTimePicker::make('checked_in_at')
                        ->label('Ngày đến cơ sở lưu trú')
                        ->native(false)
                        ->seconds(false)
                        ->timezone('Asia/Ho_Chi_Minh')
                        ->displayFormat('d/m/Y H:i'),

                    DateTimePicker::make('checked_out_at')
                        ->label('Ngày đi dự kiến')
                        ->native(false)
                        ->seconds(false)
                        ->timezone('Asia/Ho_Chi_Minh')
                        ->displayFormat('d/m/Y H:i'),
                ]),
            ]),

            Section::make('Nơi cư trú')->schema([
                Grid::make(2)->schema([
                    TextInput::make('current_residence')
                        ->label('Nơi cư trú hiện nay')
                        ->maxLength(255),

                    TextInput::make('province')
                        ->label('Tỉnh / Thành phố')
                        ->maxLength(100),

                    TextInput::make('ward')
                        ->label('Phường / Xã / Đặc khu')
                        ->maxLength(100),

                    Textarea::make('address_detail')
                        ->label('Địa chỉ chi tiết')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('order')
                ->whereHas('order', fn ($q) => $q->whereDate('created_at', today()))
            )
            ->columns([
                TextColumn::make('order.order_code')
                    ->label('Mã đơn')
                    ->searchable()
                    ->sortable()
                    ->weight(\Filament\Support\Enums\FontWeight::Bold),

                TextColumn::make('full_name')
                    ->label('Họ và tên')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cccd_number')
                    ->label('Số CCCD')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('info')
                    ->label('Giới tính / Ngày sinh / Quốc tịch')
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('room_number')
                    ->label('Số phòng')
                    ->sortable(),

                TextColumn::make('checked_in_at')
                    ->label('Ngày đến')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('checked_out_at')
                    ->label('Ngày đi dự kiến')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('reason_for_stay')
                    ->label('Lý do lưu trú')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('current_residence')
                    ->label('Nơi cư trú')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('province')
                    ->label('Tỉnh/Thành phố')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('ward')
                    ->label('Phường/Xã')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('address_detail')
                    ->label('Địa chỉ chi tiết')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->address_detail)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('date')
                    ->form([
                        DatePicker::make('from')
                            ->label('Từ ngày')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->default(today()),
                        DatePicker::make('until')
                            ->label('Đến ngày')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->default(today()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn ($q, $date) => $q->whereHas('order', fn ($oq) => $oq->whereDate('created_at', '>=', $date)))
                            ->when($data['until'], fn ($q, $date) => $q->whereHas('order', fn ($oq) => $oq->whereDate('created_at', '<=', $date)));
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators[] = 'Từ ' . \Carbon\Carbon::parse($data['from'])->format('d/m/Y');
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = 'Đến ' . \Carbon\Carbon::parse($data['until'])->format('d/m/Y');
                        }
                        return $indicators;
                    }),
            ])
            ->actions([
                EditAction::make()->label('Sửa'),
            ])
            ->defaultSort('checked_in_at', 'asc')
            ->striped();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCccdDeclarations::route('/'),
            'edit'  => Pages\EditCccdDeclaration::route('/{record}/edit'),
        ];
    }
}
