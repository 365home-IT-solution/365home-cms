<?php

declare(strict_types=1);

namespace App\Console\Commands\MediaBackup;

use App\Console\Commands\BackupOrganizedMedia;

class BackupPartnerMedia extends BackupOrganizedMedia
{
    protected $signature = 'media:backup-doi-tac
        {--dest= : Thư mục đích ảnh thường (mặc định: Windows ~/Downloads/365home, Linux ~/www/media.365home.vn/365home)}
        {--private-dest= : Thư mục riêng cho cccd, doi-tac và báo cáo (Linux mặc định: ~/backup/365home-rieng-tu)}
        {--dry-run : Chỉ thống kê, không chép file}';

    protected $description = 'Backup giấy tờ, hợp đồng, chứng từ của đối tác ra thư mục doi-tac/ (thư mục riêng tư)';

    protected ?string $group = 'doi-tac';
}
