<?php

namespace Modules\Payment\App\Filament\Resources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Payment\App\Filament\Resources\OrderResource\Forms\OrderForm;
use Modules\Payment\App\Filament\Resources\OrderResource\Tables\OrderTable;
use Modules\Payment\App\Filament\Resources\OrderResource\Pages;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Modules\Payment\Entities\Order;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    public static function getNavigationGroup(): ?string
    {
        return 'Quản lý';
    }

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    public static function getNavigationIcon(): string
    {
        return __('payment::order.resource.navigation_icon');
    }

    public static function getNavigationLabel(): string
    {
        return __('payment::order.resource.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('payment::order.resource.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payment::order.resource.plural_model_label');
    }


    public static function getNavigationBadge(): ?string
    {
        return (string) static::getEloquentQuery()->count();
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('view_any_order') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create_order') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('update_order') ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->can('delete_order') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return auth()->user()?->can('delete_any_order') ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        if ($user && $user->isSuperAdmin()) {
            return parent::getEloquentQuery();
        }

        // Nhân viên đối tác NỀN TẢNG (vd 365home) đặt phòng hộ mọi đối tác khác — họ cần xem lại
        // đúng những đơn CHÍNH HỌ đã tạo (created_by), CỘNG với đơn của đối tác mình, nhưng KHÔNG
        // được thấy đơn của đối tác khác do NHÂN VIÊN CỦA ĐỐI TÁC ĐÓ tự tạo. Phải gỡ hẳn global
        // scope 'partner' của Order để tự viết lại điều kiện lọc (partner_id CỦA MÌNH OR
        // created_by CỦA MÌNH), vì scope mặc định sẽ chỉ giữ đúng 1 vế partner_id.
        if ($user && $user->belongsToPlatformPartner()) {
            return Order::withoutGlobalScope('partner')
                ->where(function (Builder $q) use ($user) {
                    $q->where('partner_id', $user->partner_id)
                        ->orWhere('created_by', $user->id);
                });
        }

        $query = parent::getEloquentQuery();

        $allCategoryIds = $user->allowedCategoryIds();

        // Order đã lọc theo partner_id qua BelongsToPartner; allowedCategoryIds chỉ thu hẹp thêm.
        if (empty($allCategoryIds)) {
            return $query;
        }

        return $query->whereIn('category_id', $allCategoryIds);
    }

    // Ô search toàn cục (navbar) — trước đây Order không nằm trong global search nên admin
    // không thể gõ tên/SĐT khách từ bất kỳ trang nào để nhảy thẳng tới đơn. getGlobalSearchEloquentQuery()
    // mặc định gọi lại getEloquentQuery() ở trên nên vẫn tôn trọng đúng phạm vi phân quyền
    // partner/category hiện có, không lộ đơn ngoài quyền xem.
    public static function getGloballySearchableAttributes(): array
    {
        return ['order_code', 'buyer_name', 'buyer_phone'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        return $record->buyer_name ? "{$record->order_code} — {$record->buyer_name}" : $record->order_code;
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Số điện thoại' => $record->buyer_phone,
        ];
    }

    public static function form(Form $form): Form
    {
        return OrderForm::form($form);
    }

    public static function table(Table $table): Table
    {
        return OrderTable::table($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrder::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
