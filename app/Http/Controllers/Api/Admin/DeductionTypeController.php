<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\CrudsLookupTable;
use App\Http\Controllers\Controller;
use Modules\Employee\Entities\DeductionType;

// GET/POST /api/admin/deduction-types, PUT/DELETE /api/admin/deduction-types/{id}
class DeductionTypeController extends Controller
{
    use CrudsLookupTable;

    protected function modelClass(): string
    {
        return DeductionType::class;
    }

    protected function listKey(): string
    {
        return 'deduction_types';
    }

    protected function itemKey(): string
    {
        return 'deduction_type';
    }

    protected function extraRules(bool $isUpdate = false): array
    {
        $prefix = $isUpdate ? 'sometimes|' : 'required|';

        return [
            'calc_type'      => $prefix . 'in:fixed,percentage',
            'default_amount' => $prefix . 'numeric|min:0',
        ];
    }
}
