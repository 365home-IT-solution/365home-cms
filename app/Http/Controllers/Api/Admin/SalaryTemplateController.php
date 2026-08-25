<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\GeneratesUniqueSlug;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Employee\Entities\SalaryTemplate;

// Mẫu lương: gộp sẵn 1 loại lương + lương cơ bản + danh sách phụ cấp/giảm trừ áp dụng,
// để khi tạo nhân viên chỉ cần chọn 1 mẫu là tự điền toàn bộ (thay vì cấu hình lại từ đầu).
class SalaryTemplateController extends Controller
{
    use GeneratesUniqueSlug;

    // GET /api/admin/salary-templates
    public function index(Request $request): JsonResponse
    {
        $templates = SalaryTemplate::query()
            ->with('salaryType:id,name')
            ->when($request->boolean('all') === false, fn ($q) => $q->where('status', true))
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'salary_type_id', 'base_amount', 'description', 'status']);

        return response()->json(['salary_templates' => $templates]);
    }

    // GET /api/admin/salary-templates/{id}
    public function show(int $id): JsonResponse
    {
        $template = SalaryTemplate::with(['salaryType', 'allowanceTypes', 'deductionTypes'])->find($id);

        if (! $template) {
            return response()->json(['message' => 'Không tìm thấy mẫu lương.'], 404);
        }

        return response()->json(['salary_template' => $template]);
    }

    // POST /api/admin/salary-templates
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $template = SalaryTemplate::create([
            'name'            => $data['name'],
            'slug'            => $this->uniqueSlug(SalaryTemplate::class, $data['name']),
            'salary_type_id'  => $data['salary_type_id'],
            'base_amount'     => $data['base_amount'] ?? 0,
            'description'     => $data['description'] ?? null,
            'status'          => true,
        ]);

        $this->syncAllowancesAndDeductions($template, $data);

        return response()->json([
            'salary_template' => $template->load(['salaryType', 'allowanceTypes', 'deductionTypes']),
        ], 201);
    }

    // PUT /api/admin/salary-templates/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $template = SalaryTemplate::find($id);

        if (! $template) {
            return response()->json(['message' => 'Không tìm thấy mẫu lương.'], 404);
        }

        $data = $request->validate($this->rules(true));

        if (isset($data['name']) && $data['name'] !== $template->name) {
            $data['slug'] = $this->uniqueSlug(SalaryTemplate::class, $data['name'], $template->id);
        }

        $template->update(collect($data)->except(['allowances', 'deductions'])->toArray());

        $this->syncAllowancesAndDeductions($template, $data);

        return response()->json([
            'salary_template' => $template->load(['salaryType', 'allowanceTypes', 'deductionTypes']),
        ]);
    }

    // DELETE /api/admin/salary-templates/{id}
    public function destroy(int $id): JsonResponse
    {
        $template = SalaryTemplate::find($id);

        if (! $template) {
            return response()->json(['message' => 'Không tìm thấy mẫu lương.'], 404);
        }

        $template->delete();

        return response()->json(['message' => 'Đã xoá.']);
    }

    private function rules(bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes|required' : 'required';

        return [
            'name'                          => "{$required}|string|max:255",
            'salary_type_id'                => "{$required}|integer|exists:salary_types,id",
            'base_amount'                   => 'sometimes|numeric|min:0',
            'description'                   => 'nullable|string|max:1000',
            'allowances'                    => 'sometimes|array',
            'allowances.*.allowance_type_id' => 'required_with:allowances|integer|exists:allowance_types,id',
            'allowances.*.amount'           => 'nullable|numeric|min:0',
            'deductions'                    => 'sometimes|array',
            'deductions.*.deduction_type_id' => 'required_with:deductions|integer|exists:deduction_types,id',
            'deductions.*.amount'           => 'nullable|numeric|min:0',
        ];
    }

    // Chỉ đồng bộ lại phụ cấp/giảm trừ khi client thực sự gửi field đó lên
    // (để PATCH 1 phần thông tin khác không vô tình xoá hết danh sách đang có).
    private function syncAllowancesAndDeductions(SalaryTemplate $template, array $data): void
    {
        if (array_key_exists('allowances', $data)) {
            $template->allowanceTypes()->sync(collect($data['allowances'])->mapWithKeys(
                fn ($row) => [$row['allowance_type_id'] => ['amount' => $row['amount'] ?? null]]
            ));
        }

        if (array_key_exists('deductions', $data)) {
            $template->deductionTypes()->sync(collect($data['deductions'])->mapWithKeys(
                fn ($row) => [$row['deduction_type_id'] => ['amount' => $row['amount'] ?? null]]
            ));
        }
    }
}
