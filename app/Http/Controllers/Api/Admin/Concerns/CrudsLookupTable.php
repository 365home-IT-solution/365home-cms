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

    public function index(Request $request): JsonResponse
    {
        $modelClass = $this->modelClass();

        $items = $modelClass::query()
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
        ], $extra));

        return response()->json([$this->itemKey() => $item], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $modelClass = $this->modelClass();
        $item       = $modelClass::find($id);

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

    public function destroy(int $id): JsonResponse
    {
        $modelClass = $this->modelClass();
        $item       = $modelClass::find($id);

        if (! $item) {
            return response()->json(['message' => 'Không tìm thấy.'], 404);
        }

        $item->delete();

        return response()->json(['message' => 'Đã xoá.']);
    }
}
