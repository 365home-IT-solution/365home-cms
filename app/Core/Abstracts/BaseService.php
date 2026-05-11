<?php

namespace App\Core\Abstracts;

use Illuminate\Support\Facades\DB;

abstract class BaseService
{
    /** @var BaseRepository */
    protected $repository;

    public function __construct($repository) {
        $this->repository = $repository;
    }
    protected function beginTransaction(): void
    {
        DB::beginTransaction();
    }

    protected function commit(): void {
        DB::commit();
    }

    protected function rollback(): void {
        DB::rollBack();
    }

}
