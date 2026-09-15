<?php

namespace Modules\Minihouse\App\Filament\Resources\UserResource\Forms;

use App\Models\User;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Hash;

// Bố cục GIỐNG Home (Modules\User\App\Filament\Resources\UserResource\Forms\UserForm — ảnh đại
// diện + mật khẩu + timestamps ở cột trái, Tabs nội dung ở cột phải) — chỉ bỏ đi phần field không
// tồn tại ở MiniHouse (hồ sơ nhân viên/lương/chi nhánh trả lương/đối tác sở hữu), thay bằng đúng 2
// việc MiniHouse thực sự cần: Vai trò + Toà nhà được quản lý.
class UserForm
{
    private const COLUMN_SPAN = ['sm' => 1, 'lg' => 2];

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                self::createLeftColumn(),
                self::createMainTabs(),
            ])
            ->columns(3);
    }

    private static function createLeftColumn(): Group
    {
        return Group::make()
            ->schema([
                self::createAvatarUpload(),
                self::createPasswordSection(),
                self::createTimestampsSection(),
            ])
            ->columnSpan(1);
    }

    private static function createAvatarUpload(): SpatieMediaLibraryFileUpload
    {
        return SpatieMediaLibraryFileUpload::make('media')
            ->hiddenLabel()
            ->avatar()
            ->collection('avatars')
            ->alignCenter()
            ->columnSpanFull();
    }

    private static function createPasswordSection(): Section
    {
        return Section::make()
            ->schema([
                TextInput::make('password')
                    ->label('Mật khẩu')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                    ->dehydrated(fn ($state) => filled($state))
                    ->rules(['min:8']),
            ])
            ->compact();
    }

    private static function createTimestampsSection(): Section
    {
        return Section::make()
            ->schema([
                Placeholder::make('created_at')
                    ->label('Ngày tạo')
                    ->content(fn (User $record) => $record->created_at?->diffForHumans()),
                Placeholder::make('updated_at')
                    ->label('Cập nhật lúc')
                    ->content(fn (User $record) => $record->updated_at?->diffForHumans()),
            ])
            ->compact()
            ->hidden(fn (string $operation) => $operation === 'create');
    }

    private static function createMainTabs(): Tabs
    {
        return Tabs::make()
            ->schema([
                self::createInfoTab(),
                self::createRolesTab(),
            ])
            ->columnSpan(self::COLUMN_SPAN);
    }

    private static function createInfoTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Thông tin')
            ->icon('heroicon-o-information-circle')
            ->schema([
                Section::make()
                    ->schema([
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        TextInput::make('fullname')
                            ->label('Họ tên')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label('Số điện thoại')
                            ->tel()
                            ->maxLength(20)
                            ->regex('/^(0[0-9]{9,10}|\+84[0-9]{9,10})$/')
                            ->validationMessages(['regex' => 'Số điện thoại không đúng định dạng (VD: 0912345678).']),
                    ])
                    ->columns(2),
            ]);
    }

    private static function createRolesTab(): Tabs\Tab
    {
        return Tabs\Tab::make('Phân quyền')
            ->icon('heroicon-o-shield-check')
            ->schema([
                Select::make('roles')
                    ->label('Vai trò')
                    ->relationship(
                        name: 'roles',
                        titleAttribute: 'name',
                        // Chỉ hiện vai trò MiniHouse (có quyền access_minihouse) — không cho gán
                        // nhầm vai trò của Home ở đây, đúng yêu cầu tách 2 luồng dù chung 1 bảng.
                        modifyQueryUsing: fn (Builder $query) => $query->whereHas('permissions', fn (Builder $q) => $q->where('name', 'access_minihouse')),
                    )
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->columnSpanFull(),

                CheckboxList::make('minihouseZones')
                    ->label('Khu vực được quản lý')
                    ->relationship(
                        name: 'minihouseZones',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn (Builder $query) => $query->withoutGlobalScopes(),
                    )
                    ->helperText('Gán CẢ 1 khu vực = tự quản lý mọi toà nhà thuộc khu đó, kể cả toà thêm sau này — không cần tick lại từng toà.')
                    ->columns(2)
                    ->columnSpanFull(),

                CheckboxList::make('minihouseBuildings')
                    ->label('Toà nhà được quản lý riêng lẻ')
                    ->relationship(
                        name: 'minihouseBuildings',
                        titleAttribute: 'name',
                        // withoutGlobalScopes() — Building tự lọc theo toà nhà đang chọn ở bộ lọc
                        // header CỦA CHÍNH admin đang thao tác (ScopedToActiveBuilding); màn hình
                        // gán quyền cho NGƯỜI KHÁC phải luôn thấy đủ toàn bộ toà nhà.
                        modifyQueryUsing: fn (Builder $query) => $query->withoutGlobalScopes(),
                    )
                    ->helperText('Cộng thêm vào các khu vực đã chọn ở trên (nếu có). Để trống CẢ khu vực lẫn toà nhà = không giới hạn, quản lý được TẤT CẢ. Đã chọn ít nhất 1 khu vực hoặc 1 toà thì chỉ còn thấy đúng phạm vi đã gán.')
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }
}
