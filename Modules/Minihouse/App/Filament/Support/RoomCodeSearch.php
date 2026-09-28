<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Support;

use Illuminate\Database\Eloquent\Builder;

// Room::code là ACCESSOR (đọc thẳng cột "name" có sẵn trên bảng products, xem Room::getCodeAttribute())
// — không phải cột thật tên "code". Cột "address" của Building cũng không có thật trên categories, nằm
// ở bảng phụ minihouse_building_settings (xem Building::getAddressAttribute()/detail()). Filament's
// ->searchable() mặc định sinh thẳng "WHERE {tên cột trong make()} LIKE ..." nên tìm theo "code"/
// "address" ném lỗi 500 "Unknown column" — dùng ->searchable(query: ...) với các hàm dưới đây để tìm
// đúng cột thật thay vì tên accessor.
class RoomCodeSearch
{
    // Cho TextColumn::make('code') trên chính RoomTable — cột trực tiếp (không qua quan hệ).
    public static function direct(Builder $query, string $search): Builder
    {
        return $query->where('name', 'like', "%{$search}%");
    }

    // Cho TextColumn::make('room.code') ở các bảng khác (Contract, Reminder, Tenant, TenantFeedback,
    // PanoramaScene...) — $relation là TÊN quan hệ tới Room trên model của bảng đó (thường là "room").
    public static function viaRelation(Builder $query, string $search, string $relation = 'room'): Builder
    {
        return $query->whereHas($relation, fn (Builder $q) => $q->where('name', 'like', "%{$search}%"));
    }

    // Cho TextColumn::make('address') trên BuildingTable — address nằm ở bảng phụ minihouse_building_settings.
    public static function buildingAddress(Builder $query, string $search): Builder
    {
        return $query->whereHas('detail', fn (Builder $q) => $q->where('address', 'like', "%{$search}%"));
    }

    // ->sortable() cho TextColumn::make('code') trên chính RoomTable — cột trực tiếp.
    public static function sortDirect(Builder $query, string $direction): Builder
    {
        return $query->orderBy('name', $direction);
    }

    // ->sortable() cho TextColumn::make('room.code') qua quan hệ — sort theo tên phòng của bảng products
    // nối qua $foreignKey (mặc định "room_id", đúng cột khoá ngoài tới Room trên hầu hết các bảng).
    public static function sortViaRelation(Builder $query, string $direction, string $foreignKey = 'room_id'): Builder
    {
        return $query->orderBy(
            \Modules\Minihouse\App\Models\Room::query()->select('name')->whereColumn('id', $query->getModel()->getTable() . ".{$foreignKey}"),
            $direction
        );
    }

    // Cho TextColumn::make('contract.room.code') (Invoice/Transaction/ResidenceDeclaration — đi qua
    // Contract rồi mới tới Room) — $contractRelation là tên quan hệ tới Contract trên model gốc.
    public static function viaContractRoom(Builder $query, string $search, string $contractRelation = 'contract'): Builder
    {
        return $query->whereHas(
            $contractRelation,
            fn (Builder $q) => $q->whereHas('room', fn (Builder $q2) => $q2->where('name', 'like', "%{$search}%"))
        );
    }

    // ->sortable() cho TextColumn::make('contract.room.code') — Contract::room_id trỏ thẳng qua Room, nên
    // JOIN 1 lần từ Contract sang Room lấy "name" là đủ (không cần chui qua 2 tầng whereColumn lồng nhau).
    public static function sortViaContractRoom(Builder $query, string $direction, string $contractForeignKey = 'contract_id'): Builder
    {
        $table = $query->getModel()->getTable();

        $roomName = \Modules\Minihouse\App\Models\Contract::query()
            ->select('products.name')
            ->join('products', 'products.id', '=', 'minihouse_contracts.room_id')
            ->whereColumn('minihouse_contracts.id', "{$table}.{$contractForeignKey}")
            ->limit(1);

        return $query->orderBy($roomName, $direction);
    }
}
