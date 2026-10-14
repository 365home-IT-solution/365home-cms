<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// CRUD dùng chung cho các bảng danh mục dạng name/slug/description/status
// (Department, Position, SalaryType, AllowanceType, DeductionType...).
trait CrudsLookupTable
{
    use GeneratesUniqueSlug;

    // Eloquent model class được quản lý (vd Department::class)
    abstract protected function modelClass(): string;

    // Khoá bọc ngoài response, số nhiều (vd 'departments')
    abstract protected function listKey(): string;

    // Khoá bọc ngoài response, số ít (vd 'department')
    abstract protected function itemKey(): string;

    // Validation rule cho các field ngoài name/description/status (mặc định không có)
    protected function extraRules(bool $isUpdate = false): array
    {
        return [];
    }

    /**
     * Phạm vi đối tác của danh mục (chỉ áp dụng nếu bảng có cột partner_id): tài khoản đối tác chỉ thấy danh mục của đối tác mình
     * + danh mục dùng chung (partner_id null); super_admin chưa chọn đối tác thấy tất cả, đã chọn (X-Partner-Id) thì như đối tác đó.
     * BelongsToPartner của model chỉ có tác dụng trong CMS (Filament) nên API phải tự lọc.
     */
    protected function scopedQuery(Request $request)
    {
        $modelClass = $this->modelClass();
        $query = $modelClass::query();
        $table = (new $modelClass)->getTable();
        $user = $request->user();

        if (! $user instanceof \App\Models\User || ! \Illuminate\Support\Facades\Schema::hasColumn($table, 'partner_id') || $user->seesAllPartners()) {
            return $query;
        }

        $partnerId = $user->partner_id;

        return $query->where(fn ($q) => $partnerId
            ? $q->where("{$table}.partner_id", $partnerId)->orWhereNull("{$table}.partner_id")
            : $q->whereNull("{$table}.partner_id"));
    }

    /** partner_id gán cho bản ghi mới (chỉ khi bảng có cột partner_id). */
    protected function partnerAttributes(Request $request): array
    {
        $table = (new ($this->modelClass()))->getTable();
        $user = $request->user();

        if (! $user instanceof \App\Models\User || ! \Illuminate\Support\Facades\Schema::hasColumn($table, 'partner_id')) {
            return [];
        }

        return $user->seesAllPartners() ? [] : ['partner_id' => $user->partner_id];
    }

    public function index(Request $request): JsonResponse
    {
        $items = $this->scopedQuery($request)
            ->when($request->boolean('all') === false, fn ($q) => $q->where('status', true))
            ->orderBy('name')
            ->get();

        return response()->json([$this->listKey() => $items]);
    }

    public function store(Request $request): JsonResponse
    {
        $modelClass = $this->modelClass();

        $rules = array_merge([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
        ], $this->extraRules());

        $data = $request->validate($rules);

        $extra = collect($data)->except(['name', 'description'])->toArray();

        $item = $modelClass::create(array_merge([
            'name'        => $data['name'],
            'slug'        => $this->uniqueSlug($modelClass, $data['name']),
            'description' => $data['description'] ?? null,
            'status'      => true,
        ], $this->partnerAttributes($request), $extra));

        return response()->json([$this->itemKey() => $item], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $modelClass = $this->modelClass();
        $item       = $this->scopedQuery($request)->find($id);

        if (! $item) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $rules = array_merge([
            'name'        => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            // Rule 'boolean' mặc định của Laravel không chấp nhận chuỗi chữ "true"/"false"
            // (chỉ nhận true, false, 1, 0, "1", "0") — nới thêm để tránh lỗi 422 khó hiểu
            // khi gửi qua multipart/form-data.
            'status'      => 'sometimes|in:0,1,true,false',
        ], $this->extraRules(true));

        $data = $request->validate($rules);

        if (isset($data['name']) && $data['name'] !== $item->name) {
            $data['slug'] = $this->uniqueSlug($modelClass, $data['name'], $item->id);
        }

        // Ép về PHP bool thật trước khi lưu — gán thẳng chuỗi "false" vào cột boolean sẽ bị
        // MySQL ép kiểu chuỗi không phải số về 0, khiến "true" cũng vô tình lưu thành false.
        if (array_key_exists('status', $data)) {
            $data['status'] = $request->boolean('status');
        }

        $item->update($data);

        return response()->json([$this->itemKey() => $item]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $item = $this->scopedQuery($request)->find($id);

        if (! $item) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $item->delete();

        return response()->json(['message' => 'Đã xoá.']);
    }
}
