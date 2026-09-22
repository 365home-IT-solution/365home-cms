<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Minihouse\App\Models\ContractDocument;

// Gửi cho CHỦ TRỌ (Building.owner_email) ngay khi hợp đồng điện tử đủ 2 chữ ký (status=signed) —
// xem ContractDocumentService::emailOwnerFinalPdf(). Bản PDF nằm trong hộp thư của chủ trọ là bằng
// chứng ĐỘC LẬP với hệ thống 365home (docs/be-minihouse-contract-signing.md mục 11). KHÔNG gửi cho
// khách thuê ở đợt này — minihouse_tenants chưa có cột email.
class ContractDocumentSignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly ContractDocument $document)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Hợp đồng ' . $this->document->no . ' đã ký hoàn tất');
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>Hợp đồng số <strong>' . e($this->document->no) . '</strong> đã được cả hai bên ký điện tử hoàn tất.</p>'
                . '<p>Vui lòng xem file đính kèm để lưu trữ. Mã tra cứu: <strong>' . e($this->document->verify_code) . '</strong></p>'
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        if (blank($this->document->final_pdf_path)) {
            return [];
        }

        return [
            Attachment::fromStorageDisk('public', $this->document->final_pdf_path)
                ->as('hop-dong-' . $this->document->no . '.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
