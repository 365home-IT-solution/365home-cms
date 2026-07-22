<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\CrudsLookupTable;
use App\Http\Controllers\Controller;
use Modules\Employee\Entities\Position;

// GET/POST /api/admin/positions, PUT/DELETE /api/admin/positions/{id}
class PositionController extends Controller
{
    use CrudsLookupTable;

    protected function modelClass(): string
    {
        return Position::class;
    }

    protected function listKey(): string
    {
        return 'positions';
    }

    protected function itemKey(): string
    {
        return 'position';
    }
}
