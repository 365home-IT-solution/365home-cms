<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use LogicException;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Test của dự án chạy trên DB dev (DatabaseTransactions tự hoàn tác). RefreshDatabase / DatabaseMigrations chạy migrate:fresh = XOÁ SẠCH toàn bộ DB,
        // nên chỉ cho dùng khi DB là DB riêng cho test (tên kết thúc bằng "_test").
        $uses = class_uses_recursive(static::class);
        if ((isset($uses[RefreshDatabase::class]) || isset($uses[DatabaseMigrations::class]))
            && ! str_ends_with((string) config('database.connections.' . config('database.default') . '.database'), '_test')) {
            throw new LogicException(static::class . ' dùng RefreshDatabase/DatabaseMigrations nhưng DB hiện tại không phải DB test (_test) — dừng để không xoá dữ liệu.');
        }
    }
}
