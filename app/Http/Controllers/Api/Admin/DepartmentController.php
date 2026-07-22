<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\CrudsLookupTable;
use App\Http\Controllers\Controller;
use Modules\Employee\Entities\Department;

// GET/POST /api/admin/departments, PUT/DELETE /api/admin/departments/{id}
class DepartmentController extends Controller
{
    use CrudsLookupTable;

    protected function modelClass(): string
    {
        return Department::class;
    }

    protected function listKey(): string
    {
        return 'departments';
    }

    protected function itemKey(): string
    {
        return 'department';
    }
}
