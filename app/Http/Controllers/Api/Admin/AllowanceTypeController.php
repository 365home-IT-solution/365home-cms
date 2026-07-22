<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\CrudsLookupTable;
use App\Http\Controllers\Controller;
use Modules\Employee\Entities\AllowanceType;

// GET/POST /api/admin/allowance-types, PUT/DELETE /api/admin/allowance-types/{id}
class AllowanceTypeController extends Controller
{
    use CrudsLookupTable;

    protected function modelClass(): string
    {
        return AllowanceType::class;
    }

    protected function listKey(): string
    {
        return 'allowance_types';
    }

    protected function itemKey(): string
    {
        return 'allowance_type';
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
