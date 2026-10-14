<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

// Bảng tags dùng engine MyISAM (không có transaction) nên DatabaseTransactions KHÔNG rollback tiện ích
// test tạo ra — tự xoá mọi tag có id lớn hơn mốc ghi lại lúc setUp.
trait CleansUpTags
{
    private int $tagIdMark = 0;

    protected function markTags(): void
    {
        $this->tagIdMark = (int) DB::table('tags')->max('id');
    }

    protected function cleanUpTags(): void
    {
        $ids = DB::table('tags')->where('id', '>', $this->tagIdMark)->pluck('id');
        DB::table('taggables')->whereIn('tag_id', $ids)->delete();
        DB::table('tags')->whereIn('id', $ids)->delete();
    }
}
