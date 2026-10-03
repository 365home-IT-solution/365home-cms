<?php

namespace Modules\BladeThemeV1\Traits;

trait ValidationRulesTrait
{
    protected function rules()
    {
        $hasFront     = $this->authHasCccd;
        $hasOvernight = $this->hasOvernightSlotSelected();

        $rules = [
            'buyerName'          => 'required|min:2|max:50',
            'buyerPhone'         => ['required', 'regex:/^0[35789][0-9]{8}$/'],
            'buyerEmail'         => 'nullable|email',
            'guests'             => 'required|integer|min:1',
            'cccd_qr_image'         => ($hasFront ? 'nullable' : 'required') . '|image|mimes:jpg,jpeg,png,webp|max:5120',
            'accept1'            => 'accepted',
            'accept2'            => 'accepted',
            'acceptRefundPolicy' => 'accepted',
        ];

        // CCCD người đi cùng — 1 ảnh mặt có mã QR bắt buộc cho mỗi khách từ #2 trở đi khi có
        // khung giờ qua đêm được chọn (số lượng = guests - 1).
        if ($hasOvernight) {
            $companionCount = max(0, (int) $this->guests - 1);
            for ($i = 0; $i < $companionCount; $i++) {
                $rules["cccdQrImageExtra.$i"] = 'required|image|mimes:jpg,jpeg,png,webp|max:5120';
            }
        }

        return $rules;
    }

    protected function messages()
    {
        return [
            'buyerName.required' => 'Vui lòng nhập họ và tên',
            'buyerName.min' => 'Họ và tên phải có ít nhất 2 ký tự',
            'buyerName.max' => 'Họ và tên không được vượt quá 50 ký tự',
            'buyerPhone.required' => 'Vui lòng nhập số điện thoại',
            'buyerPhone.regex' => 'Số điện thoại không hợp lệ (VD: 0912345678)',
            'buyerEmail.email' => 'Email không hợp lệ',
            'guests.required' => 'Vui lòng chọn số lượng khách.',
            'cccd_qr_image.required' => 'Vui lòng tải ảnh CCCD (mặt có mã QR).',
            'cccd_qr_image.image' => 'File CCCD không phải ảnh hợp lệ.',
            'cccd_qr_image.mimes' => 'Ảnh CCCD chỉ chấp nhận định dạng JPG, PNG, WEBP.',
            'cccd_qr_image.max' => 'Ảnh CCCD không được vượt quá 5MB.',
            'cccdQrImageExtra.*.required' => 'Khung giờ qua đêm cần khai báo lưu trú cho từng người đi cùng — vui lòng tải ảnh CCCD (mặt có mã QR).',
            'cccdQrImageExtra.*.image' => 'File CCCD người đi cùng không phải ảnh hợp lệ.',
            'cccdQrImageExtra.*.mimes' => 'Ảnh CCCD người đi cùng chỉ chấp nhận định dạng JPG, PNG, WEBP.',
            'cccdQrImageExtra.*.max' => 'Ảnh CCCD người đi cùng không được vượt quá 5MB.',
            'accept1.accepted' => 'Vui lòng đồng ý với điều khoản trên trước khi đặt phòng.',
            'accept2.accepted' => 'Vui lòng đồng ý với điều khoản trên trước khi đặt phòng.',
            'acceptRefundPolicy.accepted' => 'Vui lòng đồng ý với điều khoản trên trước khi đặt phòng.',
        ];
    }

    public function updated($propertyName)
    {
        $this->validateOnly($propertyName);
    }

    protected function validateCheckout()
    {
        return $this->validate($this->rules(), $this->messages());
    }

    /**
     * Validate + dispatch notify toast với lỗi cụ thể khi fail.
     * Re-throw để Livewire vẫn set inline field errors.
     */
    protected function validateAndNotify(): void
    {
        try {
            $this->validate($this->rules(), $this->messages());
        } catch (\Illuminate\Validation\ValidationException $e) {
            $messages = collect($e->errors())->flatten()->filter()->unique()->values();
            $this->dispatch('notify', [
                'message' => $messages->implode(' | '),
                'type'    => 'error',
            ]);
            throw $e; // Livewire bắt lại để set inline errors
        }
    }
}