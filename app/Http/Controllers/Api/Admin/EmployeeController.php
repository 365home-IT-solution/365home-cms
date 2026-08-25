<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Employee\Entities\Employee;

class EmployeeController extends Controller
{
    private const DETAIL_RELATIONS = [
        'payBranch:id,name',
        'workBranches:id,name',
        'department:id,name',
        'position:id,name',
        'user:id,fullname,email',
        'province:id,name',
        'ward:code,name',
        'salaryType:id,name',
        'salaryTemplate:id,name,base_amount',
        'allowanceTypes',
        'deductionTypes',
    ];

    // GET /api/admin/employees
    // ?search= (tên/mã NV/mã chấm công/SĐT), ?department_id=, ?position_id=, ?branch_id=, ?status=, ?page=
    // Chỉ trả nhân viên do chính admin đang đăng nhập (hoặc admin con của họ) tạo — super_admin xem hết.
    public function index(Request $request): JsonResponse
    {
        $employees = Employee::query()
            ->visibleTo($request->user())
            ->with(['payBranch:id,name', 'department:id,name', 'position:id,name'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search');
                $q->where(function ($q2) use ($search) {
                    $q2->where('name', 'like', "%{$search}%")
                        ->orWhere('employee_code', 'like', "%{$search}%")
                        ->orWhere('attendance_code', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->integer('department_id')))
            ->when($request->filled('position_id'), fn ($q) => $q->where('position_id', $request->integer('position_id')))
            ->when($request->filled('branch_id'), function ($q) use ($request) {
                $branchId = $request->integer('branch_id');
                $q->where(function ($q2) use ($branchId) {
                    $q2->where('works_all_branches', true)
                        ->orWhere('pay_branch_id', $branchId)
                        ->orWhereHas('workBranches', fn ($q3) => $q3->where('categories.id', $branchId));
                });
            })
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->boolean('status')))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return response()->json($employees);
    }

    // GET /api/admin/employees/{id}
    // Không thuộc phạm vi quản lý của admin đang đăng nhập -> trả 404 (không phải 403) để không lộ
    // sự tồn tại của nhân viên đó cho admin không liên quan.
    public function show(Request $request, int $id): JsonResponse
    {
        $employee = Employee::visibleTo($request->user())->with(self::DETAIL_RELATIONS)->find($id);

        if (! $employee) {
            return response()->json(['message' => 'Không tìm thấy nhân viên.'], 404);
        }

        return response()->json(['employee' => $employee]);
    }

    // POST /api/admin/employees (multipart/form-data)
    public function store(Request $request): JsonResponse
    {
        $this->decodeJsonArrayFields($request);

        $data = $request->validate($this->rules(), $this->messages());
        $data = $this->castBooleanFields($request, $data);

        // created_by luôn lấy từ tài khoản đang đăng nhập — KHÔNG cho client tự truyền lên, nếu không
        // 1 admin có thể "gán" nhân viên mình tạo cho admin khác để né phạm vi quản lý.
        $employee = Employee::create([
            ...$this->mainFields($data),
            'created_by' => $request->user()->id,
        ]);

        $this->syncRelations($employee, $data);
        $this->handleAvatar($request, $employee);

        return response()->json([
            'employee' => $employee->fresh(self::DETAIL_RELATIONS),
        ], 201);
    }

    // POST /api/admin/employees/{id} (multipart/form-data — dùng POST thay vì PUT để hỗ trợ upload file)
    public function update(Request $request, int $id): JsonResponse
    {
        $employee = Employee::visibleTo($request->user())->find($id);

        if (! $employee) {
            return response()->json(['message' => 'Không tìm thấy nhân viên.'], 404);
        }

        $this->decodeJsonArrayFields($request);

        $data = $request->validate($this->rules(true), $this->messages());
        $data = $this->castBooleanFields($request, $data);

        $employee->update($this->mainFields($data, true));

        $this->syncRelations($employee, $data);
        $this->handleAvatar($request, $employee);

        return response()->json([
            'employee' => $employee->fresh(self::DETAIL_RELATIONS),
        ]);
    }

    // DELETE /api/admin/employees/{id}
    public function destroy(Request $request, int $id): JsonResponse
    {
        $employee = Employee::visibleTo($request->user())->find($id);

        if (! $employee) {
            return response()->json(['message' => 'Không tìm thấy nhân viên.'], 404);
        }

        $employee->delete();

        return response()->json(['message' => 'Đã xoá.']);
    }

    private function rules(bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes|required' : 'required';
        // Dùng cho rule dạng mảng (khi regex có chứa dấu "|" — xem ghi chú ở field phone/id_number).
        $requiredArr = $isUpdate ? ['sometimes', 'required'] : ['required'];
        // Khi update, loại trừ chính bản ghi đang sửa khỏi kiểm tra unique (không thì tự sửa lại
        // giá trị cũ của chính mình cũng bị báo trùng).
        $ignoreId = $isUpdate ? ',' . request()->route('id') : '';

        return [
            // NV + đúng 4 chữ số (khớp định dạng tự sinh của Employee::generateEmployeeCode())
            'employee_code'      => 'nullable|string|max:50|regex:/^NV\d{4}$/|unique:employees,employee_code' . $ignoreId,
            // Đúng 6 chữ số rồi kết thúc bằng dấu #, vd 123456#
            'attendance_code'    => 'nullable|string|max:50|regex:/^\d{6}#$/|unique:employees,attendance_code' . $ignoreId,
            'name'               => "{$required}|string|max:255",
            'avatar'             => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            // SĐT Việt Nam: 0xxxxxxxxx hoặc +84xxxxxxxxx.
            // Regex chứa dấu "|" (OR) — PHẢI viết dạng mảng, không được nối chuỗi bằng "|" như các rule
            // khác, nếu không Laravel sẽ hiểu nhầm dấu "|" trong regex là dấu phân tách rule và cắt vụn
            // pattern ra (gây lỗi runtime "preg_match(): No ending delimiter '/' found").
            'phone'              => [...$requiredArr, 'string', 'max:20', 'regex:/^(0|\+84)[0-9]{9}$/', 'unique:employees,phone' . $ignoreId],

            'pay_branch_id'      => "{$required}|integer|exists:categories,id",
            'works_all_branches' => 'sometimes|' . $this->booleanRule(),
            'work_branch_ids'                 => 'sometimes|array',
            'work_branch_ids.*'  => 'integer|exists:categories,id',

            'department_id' => "{$required}|integer|exists:departments,id",
            'position_id'   => "{$required}|integer|exists:positions,id",
            'user_id'       => 'nullable|uuid|exists:users,id',
            'hire_date'     => "{$required}|date_format:d-m-Y",
            'note'          => 'nullable|string|max:2000',

            // CMND (9 số) hoặc CCCD (12 số) — cũng chứa dấu "|" trong regex nên phải viết dạng mảng
            // (xem ghi chú chi tiết ở rule "phone" phía trên).
            'id_number'     => [...$requiredArr, 'string', 'max:20', 'regex:/^(\d{9}|\d{12})$/', 'unique:employees,id_number' . $ignoreId],
            'date_of_birth' => 'nullable|date_format:d-m-Y',
            'gender'        => "{$required}|in:male,female",

            'address'      => 'nullable|string|max:255',
            'province_id'  => "{$required}|integer|exists:provinces,id",
            'ward_code'    => "{$required}|integer|exists:wards,code",
            'email'        => "{$required}|email|max:255|unique:employees,email" . $ignoreId,
            'facebook_url' => 'nullable|string|max:255',

            'salary_type_id'     => "{$required}|integer|exists:salary_types,id",
            'salary_template_id' => 'nullable|integer|exists:salary_templates,id',
            'base_amount'        => 'nullable|numeric|min:0',

            'has_allowances'                   => 'sometimes|' . $this->booleanRule(),
            'allowances'                       => 'sometimes|array',
            'allowances.*.allowance_type_id'   => 'required_with:allowances|integer|exists:allowance_types,id',
            'allowances.*.amount'              => 'nullable|numeric|min:0',

            'has_deductions'                   => 'sometimes|' . $this->booleanRule(),
            'deductions'                       => 'sometimes|array',
            'deductions.*.deduction_type_id'   => 'required_with:deductions|integer|exists:deduction_types,id',
            'deductions.*.amount'              => 'nullable|numeric|min:0',

            'status' => 'sometimes|' . $this->booleanRule(),
        ];
    }

    // Message tiếng Việt rõ ràng cho các rule regex — message mặc định của Laravel
    // ("The x format is invalid.") không nói rõ định dạng đúng là gì.
    private function messages(): array
    {
        return [
            'employee_code.regex'   => 'Mã nhân viên phải có định dạng NV kèm đúng 4 chữ số, ví dụ NV0001.',
            'attendance_code.regex' => 'Mã chấm công phải gồm đúng 6 chữ số và kết thúc bằng dấu #, ví dụ 123456#.',
            'phone.regex'           => 'Số điện thoại không đúng định dạng (ví dụ 0912345678 hoặc +84912345678).',
            'id_number.regex'       => 'Số CMND/CCCD phải gồm đúng 9 số (CMND) hoặc 12 số (CCCD).',
        ];
    }

    // Rule 'boolean' mặc định của Laravel CHỈ chấp nhận true, false, 1, 0, "1", "0"
    // (kiểm tra in_array nghiêm ngặt) — KHÔNG chấp nhận chuỗi chữ "true"/"false" mà
    // multipart/form-data hay gửi lên. Nới thêm 2 giá trị đó để tránh lỗi 422 sai gây khó hiểu.
    private function booleanRule(): string
    {
        return 'in:0,1,true,false';
    }

    // Các field boolean-like validate bằng booleanRule() ở trên chỉ đảm bảo GIÁ TRỊ HỢP LỆ,
    // KHÔNG tự chuyển thành PHP bool thật — nếu gán thẳng chuỗi "false" vào cột boolean,
    // MySQL sẽ ép kiểu chuỗi không phải số về 0, khiến "true" cũng bị lưu thành false (sai).
    // Dùng $request->boolean() để lấy đúng giá trị true/false thật trước khi lưu.
    private function castBooleanFields(Request $request, array $data): array
    {
        foreach (['works_all_branches', 'has_allowances', 'has_deductions', 'status'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $request->boolean($field);
            }
        }

        return $data;
    }

    // Các field ghi thẳng vào bảng employees (loại trừ quan hệ nhiều-nhiều và file)
    private function mainFields(array $data, bool $isUpdate = false): array
    {
        $fields = collect($data)->except([
            'avatar', 'work_branch_ids', 'allowances', 'deductions',
        ])->toArray();

        if (isset($fields['hire_date'])) {
            $fields['hire_date'] = \Carbon\Carbon::createFromFormat('d-m-Y', $fields['hire_date'])->format('Y-m-d');
        }
        if (isset($fields['date_of_birth'])) {
            $fields['date_of_birth'] = \Carbon\Carbon::createFromFormat('d-m-Y', $fields['date_of_birth'])->format('Y-m-d');
        }

        if (! $isUpdate) {
            $fields['status'] = $fields['status'] ?? true;
        }

        return $fields;
    }

    // Chỉ đồng bộ lại quan hệ nào client thực sự gửi lên — tránh PATCH 1 phần
    // thông tin khác lại vô tình xoá hết chi nhánh làm việc/phụ cấp/giảm trừ đang có.
    private function syncRelations(Employee $employee, array $data): void
    {
        if (array_key_exists('work_branch_ids', $data)) {
            $employee->workBranches()->sync($data['work_branch_ids']);
        }

        if (array_key_exists('allowances', $data)) {
            $employee->allowanceTypes()->sync(collect($data['allowances'])->mapWithKeys(
                fn ($row) => [$row['allowance_type_id'] => ['amount' => $row['amount'] ?? null]]
            ));
        }

        if (array_key_exists('deductions', $data)) {
            $employee->deductionTypes()->sync(collect($data['deductions'])->mapWithKeys(
                fn ($row) => [$row['deduction_type_id'] => ['amount' => $row['amount'] ?? null]]
            ));
        }
    }

    private function handleAvatar(Request $request, Employee $employee): void
    {
        if ($request->hasFile('avatar')) {
            $employee->addMediaFromRequest('avatar')->toMediaCollection('avatar');
        }
    }

    // multipart/form-data không hỗ trợ mảng object lồng nhau 1 cách an toàn để sửa tay
    // (vd allowances[0][allowance_type_id]) — nên 'allowances'/'deductions' được gửi lên
    // dưới dạng 1 field text chứa chuỗi JSON, vd: [{"allowance_type_id":1,"amount":500000}].
    // Ở đây decode chuỗi đó thành mảng thật trước khi đưa vào validate(); nếu JSON sai định
    // dạng thì cố tình KHÔNG decode — rule 'array' phía dưới sẽ tự fail với lỗi 422 rõ ràng.
    private function decodeJsonArrayFields(Request $request): void
    {
        foreach (['allowances', 'deductions', 'work_branch_ids'] as $key) {
            $value = $request->input($key);

            if (is_string($value) && $value !== '') {
                $decoded = json_decode($value, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    $request->merge([$key => $decoded]);
                }
            }
        }
    }
}
