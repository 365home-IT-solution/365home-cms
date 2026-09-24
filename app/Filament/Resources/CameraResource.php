<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\CameraResource\Pages;
use App\Models\Camera;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Modules\Category\Entities\Category;

// Danh mục camera công ty — khai báo TÊN + RTSP + "stream_key" NGAY TRÊN WEB, tự đẩy sang server
// go2rtc/Frigate qua HTTP API mỗi khi lưu (App\Services\Go2RtcClient::addStream()) — không cần SSH
// vào server go2rtc để sửa file YAML nữa. RTSP mã hoá bằng APP_KEY khi lưu CSDL (xem
// App\Models\Camera::$casts). Trang xem trực tiếp: App\Filament\Pages\CameraMonitor.
class CameraResource extends Resource
{
    protected static ?string $model = Camera::class;

    protected static ?int $navigationSort = 20;

    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-video-camera';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Quản lý';
    }

    public static function getNavigationLabel(): string
    {
        return 'Camera';
    }

    public static function getModelLabel(): string
    {
        return 'Camera';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Camera';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->label('Tên camera')
                ->placeholder('VD: Cổng chính, Kho tầng 1, Quầy lễ tân')
                ->required()
                ->maxLength(255),

            TextInput::make('stream_key')
                ->label('Tên nguồn (stream key)')
                ->placeholder('VD: 89-Vp-Trong')
                ->helperText('Phải khớp CHÍNH XÁC (kể cả hoa/thường, dấu cách) với tên camera đã có sẵn trong Frigate — xem ở Settings > Enable/Disable Cameras trong Frigate.')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),

            TextInput::make('frigate_camera_name')
                ->label('Tên camera trong Frigate (chỉ điền nếu KHÁC tên nguồn ở trên)')
                ->placeholder('VD: 254-Lau-1')
                ->helperText('Dùng cho API xem lại lịch sử/ghi hình — Frigate có thể đặt tên camera khác với "Tên nguồn" go2rtc ở trên (khác hoa/thường/dấu gạch). Để trống nếu 2 tên giống hệt nhau.')
                ->maxLength(255),

            // Đa số camera của bạn ĐÃ khai báo sẵn trong Frigate (thấy ở "Enable/Disable Cameras")
            // — không cần nhập lại RTSP ở đây. Chỉ điền khi thêm 1 camera THẬT SỰ MỚI mà Frigate
            // chưa biết tới, lúc đó hệ thống mới gọi API khai báo nguồn giúp bạn.
            TextInput::make('rtsp_url')
                ->label('Địa chỉ RTSP camera (chỉ điền nếu camera CHƯA có trong Frigate)')
                ->placeholder('rtsp://admin:matkhau@192.168.1.20:554/cam/realmonitor?channel=1&subtype=0')
                ->helperText('Để trống nếu camera này đã được khai báo sẵn trong Frigate (đa số trường hợp) — chỉ cần đúng "Tên nguồn" ở trên là xem được ngay.')
                ->password()
                ->revealable()
                ->maxLength(2000),

            self::branchInput(),
            self::partnerHidden(),

            Toggle::make('status')
                ->label('Đang hoạt động')
                ->default(true)
                ->helperText('Tắt để tạm ẩn camera này khỏi trang "Xem camera" (VD camera đang bảo trì) mà không cần xoá.'),

            Textarea::make('note')
                ->label('Ghi chú')
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Tên camera')->searchable(),
                TextColumn::make('stream_key')->label('Stream key')->fontFamily('mono'),
                TextColumn::make('branch.name')->label('Chi nhánh')->toggleable(),
                ToggleColumn::make('status')->label('Hoạt động'),
                TextColumn::make('created_at')->label('Tạo lúc')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListCameras::route('/'),
            'create' => Pages\CreateCamera::route('/create'),
            'edit'   => Pages\EditCamera::route('/{record}/edit'),
        ];
    }

    // Cùng cơ chế chọn Chi nhánh (và tự suy Đối tác theo chi nhánh) đã dùng ở
    // Modules\Warehouse\...\WarehouseItemForm::branchInput()/partnerHidden() — camera cũng là dữ
    // liệu khác nhau theo TỪNG CHI NHÁNH (BelongsToBranch), không gộp chung 1 danh sách phẳng cho
    // mọi chi nhánh của đối tác.
    private static function headerActiveBranchIds(): array
    {
        if (empty(session('active_branch_ids'))) {
            return [];
        }

        return auth()->user()?->effectiveBranchIds() ?? [];
    }

    private static function singleActiveBranch(): ?Category
    {
        $ids = self::headerActiveBranchIds();

        return count($ids) === 1 ? Category::find($ids[0]) : null;
    }

    private static function partnerHidden(): Hidden
    {
        return Hidden::make('partner_id')
            ->default(fn () => self::singleActiveBranch()?->partner_id)
            ->dehydrated();
    }

    private static function branchInput(): Select
    {
        return Select::make('branch_id')
            ->label('Chi nhánh')
            ->options(function () {
                $user = auth()->user();

                if ($user?->isSuperAdmin()) {
                    $narrowedIds = self::headerActiveBranchIds();

                    $query = Category::query()
                        ->where('category_type', 'product')
                        ->whereNull('parent_id');

                    if (! empty($narrowedIds)) {
                        $query->whereIn('id', $narrowedIds);
                    }

                    return $query->orderBy('name')->pluck('name', 'id')->all();
                }

                return Category::query()
                    ->whereIn('id', $user?->effectiveBranchIds() ?? [])
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all();
            })
            ->default(fn () => self::singleActiveBranch()?->id)
            ->afterStateUpdated(function ($state, \Filament\Forms\Set $set) {
                $set('partner_id', Category::find($state)?->partner_id);
            })
            ->live()
            ->searchable()
            ->required();
    }
}
