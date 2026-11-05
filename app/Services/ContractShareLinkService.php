<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContractShareLink;
use App\Models\ContractShareLinkAccess;
use App\Models\Partner;
use App\Models\PartnerContractVersion;
use App\Models\User;
use App\Services\PdfSigning\ContractPdfRenderer;
use App\Support\PartnerContractRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Link chia sẻ tải hợp đồng đối tác (kiểu Google Drive) — dùng chung cho trang quản trị (PartnerResource) và API admin.
 *
 * - Link gắn với 1 PHIÊN BẢN hợp đồng (bản mới nhất lúc tạo link). Người mở link luôn nhận đúng trạng thái HIỆN TẠI của phiên bản đó:
 *   đã ký số → file PDF đã ký đang lưu; đối tác đã ký CKS mà nền tảng chưa ký → file đối tác đã ký; chưa ký → bản dựng từ nội dung, đóng chìm "BẢN CHƯA KÝ".
 * - Mặc định: không hết hạn, không mật khẩu. Tuỳ chọn: hết hạn sau N ngày, mật khẩu, giới hạn lượt tải. Thu hồi được bất cứ lúc nào.
 * - Quyền tạo/thu hồi: Super Admin, hoặc chủ đối tác với hợp đồng của chính mình.
 */
class ContractShareLinkService
{
    public const EXPIRY_OPTIONS = [
        0 => 'Không hết hạn',
        1 => '1 ngày',
        7 => '7 ngày',
        30 => '30 ngày',
        90 => '90 ngày',
    ];

    public const UNSIGNED_WATERMARK = 'BẢN CHƯA KÝ';

    public function canManage(?User $user, Partner $partner): bool
    {
        return $user !== null && $partner->usesContract() && ! $partner->isSystemPartner()
            && ($user->isSuperAdmin() || $user->partner_id === $partner->id);
    }

    /**
     * @param  array{expires_in_days?: int|null, password?: string|null, max_downloads?: int|null}  $options
     * @return array{link: ContractShareLink, url: string}
     */
    public function create(Partner $partner, User $actor, array $options = []): array
    {
        abort_unless($this->canManage($actor, $partner), 403, 'Bạn không có quyền chia sẻ hợp đồng của đối tác này.');

        $version = $partner->contractVersions()->first();
        if (! $version) {
            throw ValidationException::withMessages(['contract' => 'Đối tác chưa có hợp đồng để chia sẻ.']);
        }

        $days = (int) ($options['expires_in_days'] ?? 0);
        if (! array_key_exists($days, self::EXPIRY_OPTIONS)) {
            throw ValidationException::withMessages(['expires_in_days' => 'Thời hạn link không hợp lệ.']);
        }

        $token = Str::random(48);
        $link = ContractShareLink::create([
            'partner_id' => $partner->id,
            'partner_contract_version_id' => $version->id,
            'token_hash' => hash('sha256', $token),
            'token' => $token,
            'password' => filled($options['password'] ?? null) ? (string) $options['password'] : null,
            'expires_at' => $days > 0 ? now()->addDays($days) : null,
            'max_downloads' => filled($options['max_downloads'] ?? null) ? max(1, (int) $options['max_downloads']) : null,
            'created_by' => $actor->id,
        ]);

        return ['link' => $link, 'url' => route('contract-share.show', ['token' => $token])];
    }

    public function revoke(ContractShareLink $link, User $actor): void
    {
        abort_unless($this->canManage($actor, $link->partner), 403, 'Bạn không có quyền thu hồi link này.');
        if ($link->revoked_at !== null) {
            throw ValidationException::withMessages(['link' => 'Link đã được thu hồi trước đó.']);
        }

        $link->forceFill(['revoked_at' => now(), 'revoked_by' => $actor->id])->save();
    }

    /** Link theo token trên URL (kể cả đã thu hồi/hết hạn — trang công khai tự báo lý do); null = không tồn tại. */
    public function find(string $token): ?ContractShareLink
    {
        if (strlen($token) !== 48) {
            return null;
        }

        return ContractShareLink::query()->with(['partner', 'version'])->where('token_hash', hash('sha256', $token))->first();
    }

    public function checkPassword(ContractShareLink $link, string $password, Request $request): bool
    {
        $ok = $link->hasPassword() && Hash::check($password, $link->password);
        if (! $ok) {
            $this->recordAccess($link, 'password_failed', $request);
        }

        return $ok;
    }

    /** Ghi nhật ký + tăng bộ đếm. $action: view (mở trang) | preview (xem file trên trình duyệt) | download | password_failed. Xem file cũng tính là 1 lượt tải. */
    public function recordAccess(ContractShareLink $link, string $action, Request $request): void
    {
        ContractShareLinkAccess::create([
            'contract_share_link_id' => $link->id,
            'action' => $action,
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 490, ''),
        ]);

        if ($action === 'password_failed') {
            return;
        }

        $link->increment(in_array($action, ['download', 'preview'], true) ? 'download_count' : 'view_count', 1, ['last_accessed_at' => now()]);
    }

    /** Trạng thái ký của phiên bản: fully_signed | platform_signed | partner_signed | unsigned. */
    public function signatureState(PartnerContractVersion $version): string
    {
        return match (true) {
            $version->isFullySigned() => 'fully_signed',
            $version->isPlatformSigned() => 'platform_signed',
            $version->isPartnerConfirmed() => 'partner_signed',
            default => 'unsigned',
        };
    }

    public const SIGNATURE_STATES = [
        'fully_signed' => 'Đã ký đủ hai bên',
        'platform_signed' => '365 HOME đã ký, chờ đối tác ký',
        'partner_signed' => 'Đối tác đã ký, chờ 365 HOME ký',
        'unsigned' => 'Bản chưa ký',
    ];

    /**
     * File PDF người mở link nhận được. Ưu tiên file đã có chữ ký số đang lưu (không render lại để giữ nguyên chữ ký); không có thì dựng từ nội dung
     * hợp đồng — khi chưa ký đủ thì đóng chìm "BẢN CHƯA KÝ" để không bị dùng nhầm như bản chính thức.
     *
     * @return array{pdf: string, file_name: string}
     */
    public function pdf(ContractShareLink $link): array
    {
        $version = $link->version;
        $partner = $link->partner;
        $base = 'hop-dong-' . Str::slug((string) ($partner->contract_code ?: $partner->common_name ?: $partner->legal_name ?: $version->id));

        foreach (['signed_pdf', 'partner_signed_pdf'] as $collection) {
            $media = $version->getFirstMedia($collection);
            if ($media && is_file($media->getPath())) {
                $suffix = $collection === 'signed_pdf' && $version->isFullySigned() ? 'da-ky' : 'da-ky-mot-ben';

                return ['pdf' => (string) file_get_contents($media->getPath()), 'file_name' => "{$base}-{$suffix}.pdf"];
            }
        }

        $html = PartnerContractRenderer::renderFramed($version->content, $partner, $version);
        $signed = $version->isFullySigned();

        return [
            'pdf' => ContractPdfRenderer::render($html, $signed ? null : self::UNSIGNED_WATERMARK),
            'file_name' => $base . ($signed ? '-da-ky' : '-ban-chua-ky') . '.pdf',
        ];
    }

    /** Dữ liệu link cho API/trang quản trị (không bao giờ trả mật khẩu/hash). */
    public function format(ContractShareLink $link): array
    {
        $state = $link->state();

        return [
            'id' => $link->id,
            'url' => $state === 'active' ? $link->url() : null,
            'state' => $state,
            'state_label' => ContractShareLink::STATES[$state],
            'contract_version_id' => $link->partner_contract_version_id,
            'version_label' => $link->version?->version_label,
            'signature_state' => $link->version ? $this->signatureState($link->version) : null,
            'has_password' => $link->hasPassword(),
            'expires_at' => $link->expires_at?->toIso8601String(),
            'max_downloads' => $link->max_downloads,
            'view_count' => $link->view_count,
            'download_count' => $link->download_count,
            'last_accessed_at' => $link->last_accessed_at?->toIso8601String(),
            'revoked_at' => $link->revoked_at?->toIso8601String(),
            'created_by' => $link->creator ? ['id' => $link->creator->id, 'fullname' => $link->creator->fullname] : null,
            'created_at' => $link->created_at?->toIso8601String(),
        ];
    }
}
