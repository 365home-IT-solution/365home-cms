<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// SEO audit trang chủ: các H2 "Danh sách phòng - {chi nhánh}" lặp lại cùng 1 tiền tố (lại còn bị
// CSS in hoa toàn bộ). Đổi tiêu đề block room_list trong app_pages.content sang dạng tự nhiên
// "Phòng tại chi nhánh {chi nhánh}". Chỉ đụng tới tiêu đề còn đúng tiền tố cũ — tiêu đề admin đã tự
// đặt khác thì giữ nguyên.
return new class extends Migration
{
    private const OLD_PREFIX_PATTERN = '/^Danh sách phòng\s*[-–—]\s*/iu';
    private const NEW_PREFIX = 'Phòng tại chi nhánh ';
    private const OLD_PREFIX = 'Danh sách phòng - ';

    public function up(): void
    {
        $this->rewriteTitles(fn (string $title) => preg_match(self::OLD_PREFIX_PATTERN, $title)
            ? self::NEW_PREFIX . preg_replace(self::OLD_PREFIX_PATTERN, '', $title)
            : null);
    }

    public function down(): void
    {
        $this->rewriteTitles(fn (string $title) => str_starts_with($title, self::NEW_PREFIX)
            ? self::OLD_PREFIX . mb_substr($title, mb_strlen(self::NEW_PREFIX))
            : null);
    }

    // $rename trả về tiêu đề mới, hoặc null nếu không đổi.
    private function rewriteTitles(callable $rename): void
    {
        foreach (DB::table('app_pages')->get(['id', 'content']) as $page) {
            $content = json_decode((string) $page->content, true);
            if (! is_array($content)) {
                continue;
            }

            $changed = false;
            foreach ($content as $i => $block) {
                $title = $block['data']['title'] ?? null;
                if (($block['type'] ?? null) !== 'room_list' || ! is_string($title)) {
                    continue;
                }

                $newTitle = $rename($title);
                if ($newTitle !== null && $newTitle !== $title) {
                    $content[$i]['data']['title'] = $newTitle;
                    $changed = true;
                }
            }

            if ($changed) {
                DB::table('app_pages')->where('id', $page->id)->update([
                    'content' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);
            }
        }
    }
};
