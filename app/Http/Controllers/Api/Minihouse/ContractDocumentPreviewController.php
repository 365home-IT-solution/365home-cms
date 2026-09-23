<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Minihouse;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Minihouse\App\Models\ContractDocument;
use Modules\Minihouse\App\Services\ContractDocumentRenderer;
use Modules\Minihouse\App\Services\ContractDocumentService;

// Route CÔNG KHAI (không auth:sanctum) đứng sau middleware 'signed' — dùng cho html_url trả về ở
// GET .../document (xem ContractDocumentService::buildFields()). Lý do KHÔNG bắt Bearer token: app
// mở link này trong WebView để hiện hợp đồng (mục 2 của spec — WebView Android không mở được PDF
// nội tuyến, chỉ HTML mới hiện được), WebView tải thẳng URL nên không tự gắn header Authorization
// được. Chữ ký Laravel (query ?signature=...&expires=...) tự hết hạn sau 30 phút (xem
// temporarySignedRoute() ở nơi tạo URL) thay cho token, không đoán/dò được.
class ContractDocumentPreviewController extends Controller
{
    public function html(Request $request, ContractDocument $document): Response
    {
        $service = app(ContractDocumentService::class);
        $fields  = $service->buildFields($document);
        $html    = ContractDocumentRenderer::render($fields, $service->signaturesForRender($document));

        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
