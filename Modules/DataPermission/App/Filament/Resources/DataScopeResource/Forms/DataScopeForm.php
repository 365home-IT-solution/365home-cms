<?php

declare(strict_types=1);

namespace Modules\DataPermission\App\Filament\Resources\DataScopeResource\Forms;

use App\Models\User;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Illuminate\Support\Facades\DB;

class DataScopeForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Grid::make(3)
                    ->schema([
                        Section::make('Thông tin phân quyền')
                            ->description('Chọn người dùng và bảng dữ liệu cần phân quyền')
                            ->columnSpan(2)
                            ->schema([
                                Select::make('user_id')
                                    ->label('Người dùng')
                                    ->placeholder('Chọn người dùng...')
                                    ->options(fn() => User::query()
                                        ->orderBy('fullname')
                                        ->get(['id', 'fullname', 'email'])
                                        ->mapWithKeys(fn($user) => [
                                            $user->id => $user->fullname ?? $user->email ?? 'User #' . $user->id,
                                        ])
                                        ->toArray()
                                    )
                                    ->searchable()
                                    ->preload()
                                    ->required()
                                    ->columnSpanFull(),

                                Select::make('table_name')
                                    ->label('Bảng dữ liệu')
                                    ->placeholder('Chọn bảng...')
                                    ->options(fn() => self::getDatabaseTables())
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->columnSpanFull(),

                                TextInput::make('owner_column')
                                    ->label('Cột chủ sở hữu')
                                    ->placeholder('user_id')
                                    ->default('user_id')
                                    ->helperText('Tên cột trong bảng dùng để lọc dữ liệu của người dùng (thường là user_id)')
                                    ->visible(fn(Get $get) => (bool) $get('scope_own_data'))
                                    ->required(fn(Get $get) => (bool) $get('scope_own_data'))
                                    ->columnSpanFull(),

                                Textarea::make('notes')
                                    ->label('Ghi chú')
                                    ->placeholder('Nhập ghi chú (tuỳ chọn)...')
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ])
                            ->columns(1),

                        Section::make('Cấu hình quyền')
                            ->columnSpan(1)
                            ->schema([
                                Toggle::make('can_view')
                                    ->label('Cho phép xem dữ liệu')
                                    ->helperText('Người dùng này có thể xem dữ liệu của bảng đã chọn')
                                    ->default(true)
                                    ->inline(false),

                                Toggle::make('scope_own_data')
                                    ->label('Chỉ xem dữ liệu của mình')
                                    ->helperText('Lọc dữ liệu theo cột chủ sở hữu – người dùng chỉ thấy dữ liệu do chính họ tạo ra')
                                    ->default(false)
                                    ->live()
                                    ->inline(false),
                            ]),
                    ]),
            ]);
    }

    private static function getDatabaseTables(): array
    {
        $tables = DB::select('SHOW TABLES');
        $dbName = DB::getDatabaseName();
        $key = 'Tables_in_' . $dbName;

        $result = [];
        foreach ($tables as $table) {
            $name = $table->{$key};
            $result[$name] = $name;
        }

        ksort($result);

        return $result;
    }
}
