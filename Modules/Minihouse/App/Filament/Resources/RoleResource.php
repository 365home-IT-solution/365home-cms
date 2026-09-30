<?php

namespace Modules\Minihouse\App\Filament\Resources;

use BezhanSalleh\FilamentShield\Contracts\HasShieldPermissions;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use BezhanSalleh\FilamentShield\Forms\ShieldSelectAllToggle;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Forms;
use Filament\Forms\Components\Component;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Modules\Minihouse\App\Filament\Resources\RoleResource\Pages;
use Spatie\Permission\Models\Role;

// Giao diện Vai trò/Phân quyền GIỐNG HỆT panel Home (App\Filament\Resources\Shield\RoleResource —
// lưới permission theo từng Resource, tab Trang/Widget/Tuỳ chỉnh, "Chọn tất cả") — copy nguyên cơ
// chế của Filament Shield, CHỈ đổi 2 chỗ so với bản Home:
//  1. getEloquentQuery(): lọc theo quyền "access_minihouse" (không lọc theo created_by/đối tác —
//     MiniHouse không có khái niệm đối tác) — đúng yêu cầu "tách 2 luồng Home/MiniHouse dù chung 1
//     bảng roles".
//  2. CreateRole/EditRole (xem Pages/) LUÔN kèm thêm quyền "access_minihouse" vào danh sách được
//     tick, dù nhân viên không thấy/không tick dòng đó — mọi vai trò tạo ở panel này mặc nhiên phải
//     có quyền vào panel, nếu không sẽ tạo ra 1 vai trò vô dụng (gán vào vẫn không đăng nhập được).
// getAllowedPermissions() (Home dùng để giới hạn theo permission của đối tác) bỏ đi — resource này
// CHỈ super_admin truy cập được (canViewAny()), không cần giới hạn thêm theo actor.
class RoleResource extends Resource implements HasShieldPermissions
{
    protected static ?string $model = Role::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationIcon  = 'heroicon-o-shield-check';
    protected static ?string $navigationGroup = 'Phân quyền';
    protected static ?string $navigationLabel = 'Vai trò';
    protected static ?int $navigationSort     = 90;

    public static function getModelLabel(): string { return 'Vai trò'; }
    public static function getPluralModelLabel(): string { return 'Vai trò'; }

    public static function getPermissionPrefixes(): array
    {
        return ['view', 'view_any', 'create', 'update', 'delete', 'delete_any'];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('name', '!=', Utils::getPanelUserRoleName())
            ->whereHas('permissions', fn (Builder $query) => $query->where('name', 'access_minihouse'));
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit($record): bool
    {
        return static::canViewAny();
    }

    public static function canDelete($record): bool
    {
        return static::canViewAny();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Grid::make()
                    ->schema([
                        Forms\Components\Section::make()
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('Tên vai trò')
                                    ->unique(ignoreRecord: true)
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('guard_name')
                                    ->label('Guard')
                                    ->default(Utils::getFilamentAuthGuard())
                                    ->nullable()
                                    ->maxLength(255),

                                ShieldSelectAllToggle::make('select_all')
                                    ->onIcon('heroicon-s-shield-check')
                                    ->offIcon('heroicon-s-shield-exclamation')
                                    ->label('Chọn tất cả')
                                    ->helperText(fn (): HtmlString => new HtmlString('Cấp toàn bộ quyền bên dưới cho vai trò này.'))
                                    ->dehydrated(fn ($state): bool => $state),
                            ])
                            ->columns(['sm' => 2, 'lg' => 3]),
                    ]),
                Forms\Components\Tabs::make('Permissions')
                    ->contained()
                    ->tabs([
                        static::getTabFormComponentForResources(),
                        static::getTabFormComponentForPage(),
                        static::getTabFormComponentForWidget(),
                        static::getTabFormComponentForCustomPermissions(),
                    ])
                    ->columnSpan('full'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->badge()
                    ->label('Tên vai trò')
                    ->formatStateUsing(fn ($state): string => Str::headline($state))
                    ->colors(['primary'])
                    ->searchable(),
                Tables\Columns\TextColumn::make('guard_name')
                    ->badge()
                    ->label('Guard'),
                Tables\Columns\TextColumn::make('permissions_count')
                    ->badge()
                    ->label('Số quyền')
                    ->counts('permissions')
                    ->colors(['success']),
                Tables\Columns\TextColumn::make('users_count')
                    ->badge()
                    ->label('Số tài khoản')
                    ->counts('users'),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Cập nhật lúc')
                    ->dateTime(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                // Bảo vệ vai trò super_admin (dùng chung, gate bypass toàn hệ thống) và vai trò mặc
                // định do MinihousePermissionSeeder tạo — xoá nhầm khiến mọi tài khoản đang gán mất
                // quyền truy cập panel ngay lập tức.
                Tables\Actions\DeleteAction::make()
                    ->hidden(fn (Model $record) => in_array($record->name, [config('filament-shield.super_admin.name'), 'Quản lý MiniHouse'])),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ])
            ->defaultSort('created_at', 'asc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'view'   => Pages\ViewRole::route('/{record}'),
            'edit'   => Pages\EditRole::route('/{record}/edit'),
        ];
    }

    public static function getResourceEntitiesSchema(): ?array
    {
        return collect(FilamentShield::getResources())
            ->sortKeys()
            ->map(function ($entity) {
                $sectionLabel = strval(
                    static::shield()->hasLocalizedPermissionLabels()
                        ? FilamentShield::getLocalizedResourceLabel($entity['fqcn'])
                        : $entity['model']
                );

                return Forms\Components\Section::make($sectionLabel)
                    ->description(fn () => new HtmlString('<span style="word-break: break-word;">' . Utils::showModelPath($entity['fqcn']) . '</span>'))
                    ->compact()
                    ->schema([
                        static::getCheckBoxListComponentForResource($entity),
                    ])
                    ->columnSpan(static::shield()->getSectionColumnSpan())
                    ->collapsible();
            })
            ->toArray();
    }

    public static function getResourceTabBadgeCount(): ?int
    {
        return collect(FilamentShield::getResources())
            ->map(fn ($resource) => count(static::getResourcePermissionOptions($resource)))
            ->sum();
    }

    private static function buildResourcePermissionOptions(array $entity): array
    {
        return collect(Utils::getResourcePermissionPrefixes($entity['fqcn']))
            ->flatMap(function ($permission) use ($entity) {
                $name  = $permission . '_' . $entity['resource'];
                $label = static::shield()->hasLocalizedPermissionLabels()
                    ? FilamentShield::getLocalizedResourcePermissionLabel($permission)
                    : $name;

                return [$name => $label];
            })
            ->toArray();
    }

    public static function getResourcePermissionOptions(array $entity): array
    {
        return static::buildResourcePermissionOptions($entity);
    }

    public static function setPermissionStateForRecordPermissions(Component $component, string $operation, array $permissions, ?Model $record): void
    {
        if (in_array($operation, ['edit', 'view'])) {
            if (blank($record)) {
                return;
            }

            if ($component->isVisible() && count($permissions) > 0) {
                $component->state(
                    collect($permissions)
                        /** @phpstan-ignore-next-line */
                        ->filter(fn ($value, $key) => $record->checkPermissionTo($key))
                        ->keys()
                        ->toArray()
                );
            }
        }
    }

    public static function getPageOptions(): array
    {
        return collect(FilamentShield::getPages())
            ->flatMap(fn ($page) => [
                $page['permission'] => static::shield()->hasLocalizedPermissionLabels()
                    ? FilamentShield::getLocalizedPageLabel($page['class'])
                    : $page['permission'],
            ])
            ->toArray();
    }

    public static function getWidgetOptions(): array
    {
        return collect(FilamentShield::getWidgets())
            ->flatMap(fn ($widget) => [
                $widget['permission'] => static::shield()->hasLocalizedPermissionLabels()
                    ? FilamentShield::getLocalizedWidgetLabel($widget['class'])
                    : $widget['permission'],
            ])
            ->toArray();
    }

    // "access_minihouse" cố tình KHÔNG hiện trong tab "Tuỳ chỉnh" — tự động gán ngầm cho MỌI vai
    // trò tạo ở panel này (xem Pages\CreateRole/EditRole), không cần/không nên cho tick tay (dễ bị
    // bỏ tick nhầm làm vai trò mất tác dụng).
    public static function getCustomPermissionOptions(): ?array
    {
        return FilamentShield::getCustomPermissions()
            ->reject(fn ($permission) => $permission === 'access_minihouse')
            ->mapWithKeys(fn ($customPermission) => [
                $customPermission => static::shield()->hasLocalizedPermissionLabels()
                    ? str($customPermission)->headline()->toString()
                    : $customPermission,
            ])
            ->toArray();
    }

    public static function getTabFormComponentForResources(): Component
    {
        return static::shield()->hasSimpleResourcePermissionView()
            ? static::getTabFormComponentForSimpleResourcePermissionsView()
            : Forms\Components\Tabs\Tab::make('resources')
                ->label('Resources')
                ->visible(fn (): bool => (bool) Utils::isResourceEntityEnabled())
                ->badge(static::getResourceTabBadgeCount())
                ->schema([
                    Forms\Components\Grid::make()
                        ->schema(static::getResourceEntitiesSchema())
                        ->columns(static::shield()->getGridColumns()),
                ]);
    }

    public static function getCheckBoxListComponentForResource(array $entity): Component
    {
        return static::getCheckboxListFormComponent($entity['resource'], static::buildResourcePermissionOptions($entity), false);
    }

    public static function getTabFormComponentForPage(): Component
    {
        $options = static::getPageOptions();
        $count   = count($options);

        return Forms\Components\Tabs\Tab::make('pages')
            ->label('Pages')
            ->visible(fn (): bool => (bool) Utils::isPageEntityEnabled() && $count > 0)
            ->badge($count)
            ->schema([
                static::getCheckboxListFormComponent('pages_tab', $options),
            ]);
    }

    public static function getTabFormComponentForWidget(): Component
    {
        $options = static::getWidgetOptions();
        $count   = count($options);

        return Forms\Components\Tabs\Tab::make('widgets')
            ->label('Widgets')
            ->visible(fn (): bool => (bool) Utils::isWidgetEntityEnabled() && $count > 0)
            ->badge($count)
            ->schema([
                static::getCheckboxListFormComponent('widgets_tab', $options),
            ]);
    }

    public static function getTabFormComponentForCustomPermissions(): Component
    {
        $options = static::getCustomPermissionOptions();
        $count   = count($options);

        return Forms\Components\Tabs\Tab::make('custom')
            ->label('Tuỳ chỉnh')
            ->visible(fn (): bool => (bool) Utils::isCustomPermissionEntityEnabled() && $count > 0)
            ->badge($count)
            ->schema([
                static::getCheckboxListFormComponent('custom_permissions', $options),
            ]);
    }

    public static function getTabFormComponentForSimpleResourcePermissionsView(): Component
    {
        $options = FilamentShield::getAllResourcePermissions();
        $count   = count($options);

        return Forms\Components\Tabs\Tab::make('resources')
            ->label('Resources')
            ->visible(fn (): bool => (bool) Utils::isResourceEntityEnabled() && $count > 0)
            ->badge($count)
            ->schema([
                static::getCheckboxListFormComponent('resources_tab', $options),
            ]);
    }

    public static function getCheckboxListFormComponent(string $name, array $options, bool $searchable = true): Component
    {
        return Forms\Components\CheckboxList::make($name)
            ->label('')
            ->options(fn (): array => $options)
            ->searchable($searchable)
            ->afterStateHydrated(
                fn (Component $component, string $operation, ?Model $record) => static::setPermissionStateForRecordPermissions(
                    component: $component,
                    operation: $operation,
                    permissions: $options,
                    record: $record
                )
            )
            ->dehydrated(fn ($state) => ! blank($state))
            ->bulkToggleable()
            ->gridDirection('row')
            ->columns(static::shield()->getCheckboxListColumns())
            ->columnSpan(static::shield()->getCheckboxListColumnSpan());
    }

    public static function shield(): FilamentShieldPlugin
    {
        return FilamentShieldPlugin::get();
    }
}
