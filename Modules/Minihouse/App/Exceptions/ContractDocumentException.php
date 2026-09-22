<?php

namespace Modules\Minihouse\App\Exceptions;

// Ném từ ContractDocumentService cho MỌI lỗi nghiệp vụ của luồng ký hợp đồng điện tử (sai trạng
// thái, hash lệch, thiếu thông tin bắt buộc trước khi gửi...) — gom 1 chỗ để Controller (admin lẫn
// Portal) chỉ cần bắt ĐÚNG loại exception này rồi trả JSON theo $httpStatus/$errors, không phải tự
// lặp lại từng nhánh if/else status như ContractController đang làm cho 4 luồng cũ (renew/checkout/
// cancel/transfer-room) — state machine của tài liệu ký có nhiều nhánh hơn nên tách hẳn ra đây.
class ContractDocumentException extends \RuntimeException
{
    /** @param array<string, string>|null $errors */
    public function __construct(string $message, public readonly int $httpStatus = 422, public readonly ?array $errors = null)
    {
        parent::__construct($message);
    }
}
