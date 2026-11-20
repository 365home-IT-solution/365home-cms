<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Post\Entities\Post;
use Modules\Product\App\Models\Product;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

// Sao chép ảnh từ storage ra 1 thư mục backup có cấu trúc ĐỌC ĐƯỢC BẰNG MẮT (theo tên phòng / chi
// nhánh / bài viết / khách), thay vì cấu trúc {media_id}/{ulid}.jpg của Spatie — để khi mất ảnh
// (xem sự cố 0-byte 08/2026) biết ngay ảnh nào thuộc về đâu mà up lại.
//
// CHỈ ĐỌC storage + DB, không sửa/xoá gì ở nguồn. Chạy lại nhiều lần được: file đích đã có và
// cùng dung lượng thì bỏ qua. File nguồn 0 byte KHÔNG được chép (tránh đè bản backup tốt bằng bản
// hỏng) mà ghi vào báo cáo. Kết quả từng lần chạy nằm ở {dest}/_bao-cao-<nhóm>.json.
class BackupOrganizedMedia extends Command
{
    protected $signature = 'media:backup-organized
        {--dest= : Thư mục đích (mặc định: env MEDIA_BACKUP_PATH, không có thì Windows: ~/Downloads/365home, Linux: ~/www/media.365home.vn/365home)}
        {--private-dest= : Thư mục riêng cho cccd, doi-tac và báo cáo (mặc định: env MEDIA_BACKUP_PRIVATE_PATH; Linux: ~/backup/365home-rieng-tu)}
        {--only= : Chỉ chạy 1 số nhóm, cách nhau dấu phẩy: phong,chi-nhanh,bai-viet,khac,doi-tac,cccd}
        {--dry-run : Chỉ thống kê, không chép file}';

    protected $description = 'Backup ảnh ra thư mục chia theo phòng / chi nhánh / bài viết / CCCD';

    private const GROUPS = ['phong', 'chi-nhanh', 'bai-viet', 'khac', 'doi-tac', 'cccd'];

    // Nhóm "khac": thư mục => [bảng, cột tên (thử lần lượt), cột ảnh (đường dẫn trên disk public), điều kiện thêm].
    // Bảng/cột nào không tồn tại thì bỏ qua êm, không làm lệnh lỗi.
    private const OTHER_SOURCES = [
        'banner'            => ['banners', ['title', 'name'], ['image'], []],
        'tag'               => ['tags', ['name', 'title'], ['image'], []],
        'khuyen-mai'        => ['promotions', ['name', 'title'], ['image'], []],
        'dich-vu-them'      => ['additional_services', ['name', 'title'], ['image'], []],
        'tien-ich'          => ['minihouse_amenities', ['name', 'title'], ['image'], []],
        'minihouse-360'     => ['minihouse_panorama_scenes', ['name', 'title'], ['image_path'], []],
        'tinh-thanh'        => ['provinces', ['name', 'title'], ['image'], []],
        'hang-thanh-vien'   => ['membership_tiers', ['name', 'title'], ['image'], []],
        'quy-trinh'         => ['processes', ['title', 'name'], ['icon'], []],
        'danh-muc-bai-viet' => ['categories', ['name', 'title'], ['image'], ['category_type' => 'post']],
    ];

    // Lệnh riêng từng nhóm (media:backup-cccd, media:backup-phong, ... trong Commands/MediaBackup)
    // kế thừa lớp này và gán sẵn nhóm ở đây.
    protected ?string $group = null;

    private const IMAGE_EXTENSIONS = 'jpe?g|png|webp|avif|gif|svg|heic|bmp';

    // Các trường trong cccd_data dùng để chấm độ đầy đủ khi 1 người có nhiều bộ ảnh.
    private const CCCD_FIELDS = ['cccd', 'full_name', 'dob', 'gender', 'address', 'issued_date'];

    private string $dest;

    // Gốc riêng cho nhóm nhạy cảm (cccd, doi-tac) — KHÔNG được nằm trong thư mục web phục vụ (media.365home.vn).
    private string $privateDest;

    private const PRIVATE_GROUPS = ['cccd', 'doi-tac'];

    private bool $dryRun = false;

    private array $report = [];

    // Tên thư mục đã dùng trong từng nhóm → tránh 2 phòng/bài viết trùng tên đè lên nhau.
    private array $usedFolders = [];

    public function handle(): int
    {
        $this->dest   = rtrim(str_replace('\\', '/', (string) ($this->option('dest') ?: env('MEDIA_BACKUP_PATH') ?: $this->defaultDestination())), '/');
        $this->privateDest = rtrim(str_replace('\\', '/', (string) ($this->hasOption('private-dest') ? $this->option('private-dest') : '') ?: env('MEDIA_BACKUP_PRIVATE_PATH') ?: $this->defaultPrivateDestination()), '/');
        $this->dryRun = (bool) $this->option('dry-run');

        $only = $this->group ?? ($this->hasOption('only') ? $this->option('only') : null);

        $groups = $only
            ? array_values(array_intersect(self::GROUPS, array_map('trim', explode(',', $only))))
            : self::GROUPS;

        if (! $groups) {
            $this->error('--only không hợp lệ. Chọn trong: ' . implode(', ', self::GROUPS));

            return self::FAILURE;
        }

        $this->info(($this->dryRun ? '[DRY-RUN] ' : '') . "Thư mục đích: {$this->dest}");

        if (array_intersect(self::PRIVATE_GROUPS, $groups)) {
            $this->info(($this->dryRun ? '[DRY-RUN] ' : '') . "Thư mục RIÊNG TƯ (cccd, doi-tac, báo cáo): {$this->privateDest}");
        }

        foreach ($groups as $group) {
            $this->report[$group] = ['folders' => 0, 'copied' => 0, 'unchanged' => 0, 'missing' => [], 'empty' => [], 'external' => []];

            match ($group) {
                'phong'     => $this->backupRooms(),
                'chi-nhanh' => $this->backupBranches(),
                'bai-viet'  => $this->backupPosts(),
                'khac'      => $this->backupOthers(),
                'doi-tac'   => $this->backupPartners(),
                'cccd'      => $this->backupCccd(),
            };
        }

        $this->table(
            ['Nhóm', 'Thư mục', 'Đã chép', 'Đã có sẵn', 'Thiếu file nguồn', 'File nguồn 0 byte', 'Ảnh ngoài site'],
            collect($this->report)->map(fn (array $r, string $group) => [
                $group, $r['folders'], $r['copied'], $r['unchanged'], count($r['missing']), count($r['empty']), count($r['external']),
            ])->values()->all(),
        );

        if (! $this->dryRun) {
            // Mỗi nhóm 1 file báo cáo riêng, để chạy lệnh của nhóm này không xoá báo cáo nhóm khác.
            foreach ($this->report as $group => $report) {
                $this->writeFile("{$this->privateDest}/_bao-cao-{$group}.json", json_encode(
                    ['ran_at' => now()->toDateTimeString()] + $report,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ));
            }
            $this->info("Chi tiết file thiếu/hỏng: {$this->privateDest}/_bao-cao-<nhóm>.json");
        }

        return self::SUCCESS;
    }

    // Windows (máy local): ~/Downloads/365home. Linux (server): ~/www/media.365home.vn (thư mục nginx phục vụ).
    private function defaultDestination(): string
    {
        $home = (string) (getenv('USERPROFILE') ?: getenv('HOME'));

        if (PHP_OS_FAMILY === 'Windows') {
            return ($home !== '' ? $home : dirname(base_path())) . '/Downloads/365home';
        }

        return $home !== '' && is_dir("{$home}/www/media.365home.vn") ? "{$home}/www/media.365home.vn/365home" : dirname(base_path()) . '/365home';
    }

    // Giấy tờ tuỳ thân / pháp lý + báo cáo (có tên người) tuyệt đối không để trong thư mục web.
    // Windows: cùng gốc với ảnh thường. Linux: ~/backup/365home-rieng-tu, nằm ngoài ~/www.
    private function defaultPrivateDestination(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $this->dest;
        }

        $home = (string) (getenv('HOME') ?: '');

        return $home !== '' ? "{$home}/backup/365home-rieng-tu" : dirname(base_path()) . '/365home-rieng-tu';
    }

    private function rootFor(string $relative): string
    {
        $first = explode('/', ltrim(str_replace('\\', '/', $relative), '/'), 2)[0];

        return in_array($first, self::PRIVATE_GROUPS, true) ? $this->privateDest : $this->dest;
    }
    // phong/<chi nhánh>/<tên phòng>/anh-bia|thu-vien|chi-tiet/...
    private function backupRooms(): void
    {
        $rooms = DB::table('products')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']);

        // Phòng → tên chi nhánh (category_type = product, qua bảng pivot categorizables).
        $branchOf = DB::table('categorizables as cz')
            ->join('categories as c', 'c.id', '=', 'cz.category_id')
            ->where('cz.categorizable_type', Product::class)
            ->where('c.category_type', 'product')
            ->whereIn('cz.categorizable_id', $rooms->pluck('id'))
            ->orderByDesc('c.id')
            ->pluck('c.name', 'cz.categorizable_id');

        $media = Media::query()
            ->where('model_type', Product::class)
            ->whereIn('model_id', $rooms->pluck('id'))
            ->orderBy('order_column')
            ->get()
            ->groupBy('model_id');

        $roomImages = Schema::hasTable('room_images')
            ? DB::table('room_images')->whereIn('room_id', $rooms->pluck('id'))->orderBy('sort_order')->get()->groupBy('room_id')
            : collect();

        foreach ($rooms as $room) {
            $branch = $this->folderName('phong-chi-nhanh', $branchOf->get($room->id) ?: 'Chưa gắn chi nhánh', '', reuse: true);
            // Tên phòng chỉ cần duy nhất TRONG 1 chi nhánh (2 chi nhánh cùng có "Phòng 101" là bình thường).
            $dir = "phong/{$branch}/" . $this->folderName("phong/{$branch}", $room->name, (string) $room->id);

            foreach ($media->get($room->id, []) as $item) {
                $this->copyMedia('phong', $item, $dir . '/' . $this->collectionFolder($item->collection_name));
            }

            // room_images.path là JSON: mảng path, hoặc mảng nhóm {title, images: [...]}.
            foreach ($roomImages->get($room->id, []) as $row) {
                foreach ($this->imagePathsIn(json_decode((string) $row->path, true) ?? $row->path) as $path) {
                    $this->copyFromDisk('phong', $row->disk ?: 'public', $path, "{$dir}/chi-tiet/{$row->type}/" . basename($path));
                }
            }

            $this->report['phong']['folders']++;
        }
    }

    // chi-nhanh/<tên chi nhánh>/<ảnh> — chỉ category_type = product (bảng categories dùng chung với bài viết).
    private function backupBranches(): void
    {
        $branches = DB::table('categories')
            ->where('category_type', 'product')
            ->whereNull('deleted_at')
            ->whereNotNull('image')
            ->where('image', '<>', '')
            ->orderBy('name')
            ->get(['id', 'name', 'image']);

        foreach ($branches as $branch) {
            $dir = 'chi-nhanh/' . $this->folderName('chi-nhanh', $branch->name, (string) $branch->id);

            $this->copyFromDisk('chi-nhanh', 'public', $branch->image, $dir . '/' . basename($branch->image));
            $this->report['chi-nhanh']['folders']++;
        }
    }

    // bai-viet/<tiêu đề>/anh-chinh/... + bai-viet/<tiêu đề>/noi-dung/... (ảnh <img> trong content).
    private function backupPosts(): void
    {
        $media = Media::query()->where('model_type', Post::class)->orderBy('order_column')->get()->groupBy('model_id');

        foreach (DB::table('posts')->orderBy('title')->get(['id', 'title', 'slug', 'content']) as $post) {
            $dir = 'bai-viet/' . $this->folderName('bai-viet', $post->title ?: $post->slug, (string) $post->id);

            foreach ($media->get($post->id, []) as $item) {
                $this->copyMedia('bai-viet', $item, $dir . '/' . $this->collectionFolder($item->collection_name));
            }

            foreach ($this->contentImages((string) $post->content) as $index => $src) {
                $path = $this->publicDiskPath($src);

                if ($path === null) {
                    $this->report['bai-viet']['external'][] = "{$post->slug}: {$src}";

                    continue;
                }

                // Đánh số theo thứ tự xuất hiện trong bài để dễ đặt lại đúng vị trí.
                $this->copyFromDisk('bai-viet', 'public', $path, sprintf('%s/noi-dung/%02d-%s', $dir, $index + 1, basename($path)));
            }

            $this->report['bai-viet']['folders']++;
        }
    }

    // khac/<nhóm>/<tên bản ghi>/<ảnh> — banner, tag, khuyến mãi, tiện ích, tỉnh thành, hạng thành viên...
    private function backupOthers(): void
    {
        foreach (self::OTHER_SOURCES as $folder => [$table, $nameColumns, $imageColumns, $where]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $imageColumns = array_values(array_filter($imageColumns, fn (string $c) => Schema::hasColumn($table, $c)));
            $nameColumn   = collect($nameColumns)->first(fn (string $c) => Schema::hasColumn($table, $c));

            if (! $imageColumns) {
                continue;
            }

            $query = DB::table($table);

            if (Schema::hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            foreach ($where as $column => $value) {
                $query->where($column, $value);
            }

            foreach ($query->orderBy('id')->get() as $row) {
                $paths = [];

                foreach ($imageColumns as $column) {
                    $paths = array_merge($paths, $this->imagePathsIn(json_decode((string) $row->{$column}, true) ?? $row->{$column}));
                }

                if (! $paths) {
                    continue;
                }

                $dir = "khac/{$folder}/" . $this->folderName("khac/{$folder}", $nameColumn ? (string) $row->{$nameColumn} : null, (string) $row->id);

                foreach (array_unique($paths) as $path) {
                    $this->copyFromDisk('khac', 'public', $path, "{$dir}/" . basename($path));
                }

                $this->report['khac']['folders']++;
            }
        }
    }

    // doi-tac/<tên đối tác>/ho-so | giay-to-phap-ly/<loại> | hop-dong/<phiên bản> | ky-quy
    // Toàn bộ là media của Spatie (nhiều file nằm ở disk local, getPath() tự trỏ đúng). Quyền 0700 như CCCD.
    private function backupPartners(): void
    {
        $mediaOf = fn (string $model, $ids) => Media::query()
            ->where('model_type', $model)
            ->whereIn('model_id', $ids)
            ->orderBy('order_column')
            ->get()
            ->groupBy('model_id');

        $partners = DB::table('partners')->orderBy('name')->get(['id', 'name']);
        $ids      = $partners->pluck('id');

        $own = $mediaOf(\App\Models\Partner::class, $ids);

        $docs = DB::table('partner_legal_documents')->whereIn('partner_id', $ids)->get(['id', 'partner_id', 'type', 'name'])->groupBy('partner_id');
        $docMedia = $mediaOf(\App\Models\PartnerLegalDocument::class, $docs->flatten()->pluck('id'));

        $contracts = DB::table('partner_contract_versions')->whereIn('partner_id', $ids)->get(['id', 'partner_id', 'version_label'])->groupBy('partner_id');
        $contractMedia = $mediaOf(\App\Models\PartnerContractVersion::class, $contracts->flatten()->pluck('id'));

        $entries = DB::table('partner_escrow_entries')->whereIn('partner_id', $ids)->get(['id', 'partner_id'])->groupBy('partner_id');
        $entryMedia = $mediaOf(\App\Models\PartnerEscrowEntry::class, $entries->flatten()->pluck('id'));

        $deductions = DB::table('partner_escrow_deductions')->whereIn('partner_id', $ids)->get(['id', 'partner_id', 'case_code'])->groupBy('partner_id');
        $deductionMedia = $mediaOf(\App\Models\PartnerEscrowDeduction::class, $deductions->flatten()->pluck('id'));

        foreach ($partners as $partner) {
            $dir = 'doi-tac/' . $this->folderName('doi-tac', $partner->name, (string) $partner->id);

            foreach ($own->get($partner->id, []) as $item) {
                $this->copyMedia('doi-tac', $item, "{$dir}/ho-so/" . $this->collectionFolder($item->collection_name));
            }

            foreach ($docs->get($partner->id, []) as $doc) {
                $type = $this->folderName("{$dir}/gpl", $doc->name ?: $doc->type, (string) $doc->id);

                foreach ($docMedia->get($doc->id, []) as $item) {
                    $this->copyMedia('doi-tac', $item, "{$dir}/giay-to-phap-ly/{$type}/" . $this->collectionFolder($item->collection_name));
                }
            }

            foreach ($contracts->get($partner->id, []) as $contract) {
                $version = $this->folderName("{$dir}/hd", $contract->version_label ?: 'phien-ban', (string) $contract->id);

                foreach ($contractMedia->get($contract->id, []) as $item) {
                    $this->copyMedia('doi-tac', $item, "{$dir}/hop-dong/{$version}/" . $this->collectionFolder($item->collection_name));
                }
            }

            foreach ($entries->get($partner->id, []) as $entry) {
                foreach ($entryMedia->get($entry->id, []) as $item) {
                    $this->copyMedia('doi-tac', $item, "{$dir}/ky-quy/but-toan-{$entry->id}");
                }
            }

            foreach ($deductions->get($partner->id, []) as $deduction) {
                foreach ($deductionMedia->get($deduction->id, []) as $item) {
                    $this->copyMedia('doi-tac', $item, "{$dir}/ky-quy/khau-tru-" . Str::slug($deduction->case_code ?: (string) $deduction->id) . '/' . $this->collectionFolder($item->collection_name));
                }
            }

            $this->report['doi-tac']['folders']++;
        }
    }

    // cccd/<khach-hang|khach-vang-lai|nguoi-di-cung>/<Họ tên - SĐT>/mat-truoc|mat-sau|qr.<ext> + thong-tin.json.
    // khach-vang-lai = đơn không gắn tài khoản (customer_id null).
    // 1 người (cùng tên + SĐT) có thể để lại nhiều bộ ảnh qua nhiều đơn/tài khoản → chỉ giữ bộ có
    // cccd_data đầy đủ nhất (ưu tiên đọc từ QR, rồi tới bộ mới nhất) mà file ảnh còn tồn tại.
    private function backupCccd(): void
    {
        $people = [];

        foreach ($this->cccdCandidates() as $candidate) {
            $candidate['files'] = array_filter($candidate['files'], fn (?string $path) => filled($path));

            if (! $candidate['files']) {
                continue;
            }

            $data  = is_array($candidate['data']) ? $candidate['data'] : (json_decode((string) $candidate['data'], true) ?: []);
            $name  = trim((string) ($data['full_name'] ?? '')) ?: trim((string) $candidate['name']);
            $phone = preg_replace('/\D+/', '', (string) $candidate['phone']);

            if ($name === '' && $phone === '') {
                continue;
            }

            $existing = array_filter($candidate['files'], fn (string $path) => $this->sourceSize('public', $path) > 0);
            $filled   = count(array_filter(self::CCCD_FIELDS, fn (string $field) => filled($data[$field] ?? null)));

            $candidate['data']  = $data;
            $candidate['name']  = Str::title(Str::lower($name));
            $candidate['phone'] = $phone;
            $candidate['score'] = [count($existing) > 0, $filled, ($data['source'] ?? null) === 'qr', count($existing), (string) $candidate['at']];

            // Không có SĐT (người đi cùng) → phân biệt bằng số CCCD để 2 người trùng tên không gộp nhầm.
            $key = Str::slug($name) . '|' . ($phone ?: 'cccd-' . ($data['cccd'] ?? ''));

            // Cùng 1 người xuất hiện ở nhiều nơi (vừa có tài khoản, vừa từng đặt như khách vãng lai):
            // xếp vào loại ưu tiên cao nhất, dù bộ ảnh được chọn lấy từ nơi khác.
            $rank = ['khach-hang' => 0, 'khach-vang-lai' => 1, 'nguoi-di-cung' => 2];
            $kind = isset($people[$key]) && $rank[$people[$key]['kind']] < $rank[$candidate['kind']] ? $people[$key]['kind'] : $candidate['kind'];

            if (! isset($people[$key]) || $candidate['score'] > $people[$key]['score']) {
                $people[$key] = $candidate;
            }

            $people[$key]['kind'] = $kind;
        }

        foreach ($people as $person) {
            $label = $person['name'] ?: 'Không tên';
            $label .= ' - ' . ($person['phone'] ?: (filled($person['data']['cccd'] ?? null) ? 'CCCD ' . $person['data']['cccd'] : 'không SĐT'));
            $dir = "cccd/{$person['kind']}/" . $this->folderName("cccd/{$person['kind']}", $label, substr(md5($person['origin']), 0, 6));

            foreach ($person['files'] as $side => $path) {
                $this->copyFromDisk('cccd', 'public', $path, "{$dir}/{$side}." . (pathinfo($path, PATHINFO_EXTENSION) ?: 'jpg'));
            }

            if (! $this->dryRun && is_dir($this->rootFor($dir) . "/{$dir}")) {
                $this->writeFile($this->rootFor($dir) . "/{$dir}/thong-tin.json", json_encode([
                    'ho_ten'    => $person['name'],
                    'sdt'       => $person['phone'],
                    'loai'      => $person['kind'],
                    'nguon'     => $person['origin'],
                    'thoi_diem' => (string) $person['at'],
                    'cccd_data' => $person['data'],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            $this->report['cccd']['folders']++;
        }
    }

    // Gom mọi nơi đang lưu ảnh CCCD về 1 dạng chung: đơn (khách chính + khách 2), khách đi cùng
    // theo đơn, tài khoản khách, người đi cùng đã lưu, các lần xác thực lại.
    private function cccdCandidates(): \Generator
    {
        $files = fn (object $row, string $suffix = '') => [
            'mat-truoc' => $row->{'cccd_front' . $suffix} ?? null,
            'mat-sau'   => $row->{'cccd_back' . $suffix} ?? null,
            'qr'        => $suffix === '' ? ($row->cccd_qr_image ?? null) : null,
        ];

        $orders = DB::table('orders')
            ->where(fn ($q) => $q->whereNotNull('cccd_front')->orWhereNotNull('cccd_back')->orWhereNotNull('cccd_qr_image')->orWhereNotNull('cccd_front_2'))
            ->orderBy('id')
            ->select(['id', 'order_code', 'buyer_name', 'buyer_phone', 'buyer_phone_2', 'cccd_front', 'cccd_back', 'cccd_qr_image', 'cccd_data', 'cccd_front_2', 'cccd_back_2', 'cccd_data_2', 'customer_id', 'created_at']);

        foreach ($orders->lazy(500) as $order) {
            yield ['kind' => $order->customer_id ? 'khach-hang' : 'khach-vang-lai', 'name' => $order->buyer_name, 'phone' => $order->buyer_phone, 'data' => $order->cccd_data, 'files' => $files($order), 'origin' => "Đơn {$order->order_code} (khách chính)", 'at' => $order->created_at];
            yield ['kind' => 'nguoi-di-cung', 'name' => null, 'phone' => $order->buyer_phone_2, 'data' => $order->cccd_data_2, 'files' => $files($order, '_2'), 'origin' => "Đơn {$order->order_code} (khách 2)", 'at' => $order->created_at];
        }

        $guests = DB::table('order_guest_cccds as g')
            ->join('orders as o', 'o.id', '=', 'g.order_id')
            ->orderBy('g.id')
            ->select(['g.*', 'o.order_code', 'o.buyer_phone_2']);

        foreach ($guests->lazy(500) as $guest) {
            // buyer_phone_2 là SĐT của khách thứ 2; khách thứ 3 trở đi không có SĐT riêng.
            yield ['kind' => 'nguoi-di-cung', 'name' => null, 'phone' => (int) $guest->guest_index === 2 ? $guest->buyer_phone_2 : null, 'data' => $guest->cccd_data, 'files' => $files($guest), 'origin' => "Đơn {$guest->order_code} (khách {$guest->guest_index})", 'at' => $guest->created_at];
        }

        foreach (DB::table('customers')->whereNull('deleted_at')->orderBy('created_at')->lazy(500) as $customer) {
            yield ['kind' => 'khach-hang', 'name' => $customer->fullname, 'phone' => $customer->phone, 'data' => $customer->cccd_data, 'files' => $files($customer), 'origin' => "Tài khoản khách {$customer->id}", 'at' => $customer->updated_at];
        }

        foreach (DB::table('customer_companions')->orderBy('id')->lazy(500) as $companion) {
            yield ['kind' => 'nguoi-di-cung', 'name' => $companion->full_name, 'phone' => null, 'data' => $companion->cccd_data, 'files' => $files($companion), 'origin' => "Người đi cùng #{$companion->id}", 'at' => $companion->updated_at];
        }

        $verifications = DB::table('customer_cccd_verifications as v')
            ->join('customers as c', 'c.id', '=', 'v.customer_id')
            ->orderBy('v.id')
            ->select(['v.*', 'c.fullname', 'c.phone']);

        foreach ($verifications->lazy(500) as $verification) {
            yield ['kind' => 'khach-hang', 'name' => $verification->fullname, 'phone' => $verification->phone, 'data' => $verification->cccd_data, 'files' => ['qr' => $verification->cccd_qr_image], 'origin' => "Xác thực lần {$verification->attempt} của khách {$verification->customer_id}", 'at' => $verification->created_at];
        }
    }

    private function copyMedia(string $group, Media $media, string $targetDir): void
    {
        // Chỉ chép ảnh GỐC — các bản conversion (thumb/card/...) tạo lại được bằng media-library:regenerate.
        $this->copyFile($group, $media->getPath(), "{$targetDir}/{$media->id}-{$media->file_name}", "media #{$media->id}");
    }

    private function copyFromDisk(string $group, string $disk, string $path, string $target): void
    {
        $this->copyFile($group, Storage::disk($disk)->path(ltrim($path, '/')), $target, $path);
    }

    private function copyFile(string $group, string $source, string $target, string $label): void
    {
        if (! is_file($source)) {
            $this->report[$group]['missing'][] = "{$target} ← {$label}";

            return;
        }

        $size = filesize($source);

        if ($size === 0) {
            $this->report[$group]['empty'][] = "{$target} ← {$label}";

            return;
        }

        $destination = $this->rootFor($target) . "/{$target}";

        if (is_file($destination) && filesize($destination) === $size) {
            $this->report[$group]['unchanged']++;

            return;
        }

        if (! $this->dryRun) {
            $this->ensureDirectory(dirname($destination));

            // Chép ra file tạm rồi rename: lỡ tiến trình chết giữa chừng thì không để lại file dở.
            $temp = $destination . '.part';

            if (! @copy($source, $temp) || filesize($temp) !== $size || ! @rename($temp, $destination)) {
                @unlink($temp);
                $this->report[$group]['missing'][] = "{$target} ← {$label} (chép lỗi)";

                return;
            }
        }

        $this->report[$group]['copied']++;
    }

    private function sourceSize(string $disk, string $path): int
    {
        $full = Storage::disk($disk)->path(ltrim($path, '/'));

        return is_file($full) ? (int) filesize($full) : 0;
    }

    private function writeFile(string $path, string $contents): void
    {
        $this->ensureDirectory(dirname($path));
        file_put_contents($path, $contents);
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        // Tạo thư mục gốc trước với quyền thường, để mkdir đệ quy bên dưới không áp 0700 lên nó.
        foreach ([$this->dest, $this->privateDest] as $root) {
            if (! is_dir($root)) {
                mkdir($root, 0755, true);
            }
        }

        // Thư mục CCCD và hồ sơ đối tác chứa giấy tờ tuỳ thân / pháp lý → chỉ chủ sở hữu đọc được.
        if (! is_dir($dir)) {
            $private = false;

            foreach (self::PRIVATE_GROUPS as $group) {
                $private = $private || str_contains($dir . '/', "{$this->privateDest}/{$group}/") || str_contains($dir . '/', "{$this->dest}/{$group}/");
            }

            mkdir($dir, $private ? 0700 : 0755, true);
        }
    }

    private function collectionFolder(string $collection): string
    {
        return Str::slug($collection) ?: 'khac';
    }

    // Giữ nguyên tên tiếng Việt cho dễ đọc, chỉ bỏ ký tự không hợp lệ với tên thư mục.
    private function folderName(string $group, ?string $name, string $fallbackSuffix, bool $reuse = false): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('#[/\\\\:*?"<>|\x00-\x1F]#u', ' ', (string) $name)), ' .');
        $clean = Str::limit($clean ?: 'khong-ten', 120, '');

        if (! $reuse && isset($this->usedFolders[$group][Str::lower($clean)])) {
            $clean .= ' (' . Str::limit($fallbackSuffix, 12, '') . ')';
        }

        $this->usedFolders[$group][Str::lower($clean)] = true;

        return $clean;
    }

    private function contentImages(string $html): array
    {
        preg_match_all('/<img\b[^>]*?\b(?:data-)?src\s*=\s*(["\'])(.*?)\1/is', $html, $matches);

        return array_values(array_unique(array_filter(array_map(
            fn (string $src) => trim(html_entity_decode($src)),
            $matches[2],
        ), fn (string $src) => $src !== '' && ! str_starts_with($src, 'data:'))));
    }

    // URL ảnh trong bài viết → đường dẫn tương đối trên disk public; null nếu ảnh nằm ngoài site.
    private function publicDiskPath(string $src): ?string
    {
        $path = rawurldecode((string) parse_url($src, PHP_URL_PATH));
        $host = parse_url($src, PHP_URL_HOST);

        if ($host && ! Str::contains($host, ['365home', parse_url((string) config('app.url'), PHP_URL_HOST) ?: '365home'])) {
            return null;
        }

        return Str::startsWith($path, '/storage/') ? Str::after($path, '/storage/') : null;
    }

    private function imagePathsIn(mixed $value): array
    {
        if (is_string($value)) {
            return preg_match('/\.(' . self::IMAGE_EXTENSIONS . ')$/i', $value) ? [$value] : [];
        }

        return is_array($value) ? array_merge([], ...array_map(fn ($item) => $this->imagePathsIn($item), array_values($value))) : [];
    }
}
