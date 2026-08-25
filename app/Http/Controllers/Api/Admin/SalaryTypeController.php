<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\CrudsLookupTable;
use App\Http\Controllers\Controller;
use Modules\Employee\Entities\SalaryType;

// GET/POST /api/admin/salary-types, PUT/DELETE /api/admin/salary-types/{id}
class SalaryTypeController extends Controller
{
    use CrudsLookupTable;

    protected function modelClass(): string
    {
        return SalaryType::class;
    }

    protected function listKey(): string
    {
        return 'salary_types';
    }

    protected function itemKey(): string
    {
        return 'salary_type';
    }
}
