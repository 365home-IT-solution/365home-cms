<?php

declare(strict_types=1);

namespace App\Services\Camera\Cloud;

use RuntimeException;

/** Lỗi khi gọi API đám mây của hãng — message đã là tiếng Việt, hiển thị thẳng cho người dùng được. */
class CloudProviderException extends RuntimeException {}
