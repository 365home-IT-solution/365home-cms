<?php

namespace App\Console\Commands\PriceBoards;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Kiểm tra dữ liệu bảng giá: chỉ ĐỌC, không sửa. Trả mã lỗi 1 nếu có bất thường.
 */
class AuditPriceBoards extends Command
{
    protected $signature = 'price-boards:audit';

    protected $description = 'Kiểm tra dữ liệu bảng giá (lệch đối tác, item mồ côi, nhiều bảng mặc định, mẫu có item, bảng trùng ngày).';

    public function handle(): int
    {
        $issues = [];

        $count = fn (string $label, $rows) => $rows ? $issues[] = [$label, count($rows), implode(', ', array_slice($rows, 0, 10))] : null;

        $count('Item lệch đối tác với bảng', DB::table('price_board_items as i')
            ->join('price_boards as b', 'b.id', '=', 'i.price_board_id')
            ->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('b.is_default', false)->where('b.is_template', false)
            ->whereNotNull('b.partner_id')->whereColumn('p.partner_id', '!=', 'b.partner_id')
            ->pluck('i.id')->all());

        $count('Item mồ côi (phòng đã xoá)', DB::table('price_board_items as i')
            ->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->whereNull('p.id')->pluck('i.id')->all());

        $count('Bảng mẫu có item', DB::table('price_boards as b')
            ->where('b.is_template', true)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('price_board_items')->whereColumn('price_board_items.price_board_id', 'b.id'))
            ->pluck('b.id')->all());

        $count('Bộ giá mồ côi', DB::table('price_board_sheets as s')
            ->leftJoin('price_boards as b', 'b.id', '=', 's.price_board_id')
            ->whereNull('b.id')->pluck('s.id')->all());

        $defaults = DB::table('price_boards')->where('is_default', true)->pluck('id')->all();
        if (count($defaults) > 1) {
            $issues[] = ['Nhiều hơn 1 bảng mặc định', count($defaults), implode(', ', $defaults)];
        }

        $active = DB::table('price_boards')->where('is_default', false)->where('is_template', false)->where('is_active', true)->get();
        $dups   = [];

        foreach ($active as $a) {
            foreach ($active as $b) {
                if ($a->id >= $b->id || (int) $a->priority !== (int) $b->priority) {
                    continue;
                }
                $aEnd = $a->end_date ?? '9999-12-31';
                $bEnd = $b->end_date ?? '9999-12-31';
                if (($a->start_date ?? '0000-01-01') > $bEnd || ($b->start_date ?? '0000-01-01') > $aEnd) {
                    continue;
                }
                $ad = (array) json_decode((string) $a->days_of_week, true);
                $bd = (array) json_decode((string) $b->days_of_week, true);
                if ($ad && $bd && ! array_intersect($ad, $bd)) {
                    continue;
                }
                $shared = DB::table('price_board_items as x')
                    ->join('price_board_items as y', 'y.product_id', '=', 'x.product_id')
                    ->where('x.price_board_id', $a->id)->where('y.price_board_id', $b->id)->exists();
                if ($shared) {
                    $dups[] = "{$a->id}&{$b->id}";
                }
            }
        }
        $count('Bảng đang bật trùng ngày/phòng/ưu tiên', $dups);

        if (! $issues) {
            $this->info('Dữ liệu bảng giá sạch.');

            return self::SUCCESS;
        }

        $this->table(['Vấn đề', 'Số lượng', 'Ví dụ'], $issues);

        return self::FAILURE;
    }
}
