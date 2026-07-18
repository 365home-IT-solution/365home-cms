<?php

namespace Modules\BladeThemeV1\Traits;

trait ValidationRulesTrait
{
    protected function rules()
    {
        $hasFront     = $this->isAuthUser && !empty($this->authCccdFront);
        $hasBack      = $this->isAuthUser && !empty($this->authCccdBack);
        $hasOvernight = $this->hasOvernightSlotSelected();

        return [
            'buyerName'          => 'required|min:2|max:50',
            'buyerPhone'         => ['required', 'regex:/^0[35789][0-9]{8}$/'],
            'buyerEmail'         => 'nullable|email',
            'guests'             => 'required|integer|min:1',
            'cccd_front'         => $hasFront ? 'nullable' : 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
            'cccd_back'          => $hasBack  ? 'nullable' : 'required|file|mimes:jpg,jpeg,png,webp|max:5120',
            // CCCD + SĐT người đi cùng — chỉ bắt buộc khi có khung giờ qua đêm được chọn.
            'cccd_front_2'       => $hasOvernight ? 'required|file|mimes:jpg,jpeg,png,webp|max:5120' : 'nullable',
            'cccd_back_2'        => $hasOvernight ? 'required|file|mimes:jpg,jpeg,png,webp|max:5120' : 'nullable',
            'buyerPhone2'        => $hasOvernight ? ['required', 'regex:/^0[35789][0-9]{8}$/'] : 'nullable',
            'accept1'            => 'accepted',
            'accept2'            => 'accepted',
            'acceptRefundPolicy' => 'accepted',
        ];
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
            'cccd_front.required' => 'Vui lòng tải lên mặt trước CCCD.',
            'cccd_front.file' => 'File CCCD mặt trước không hợp lệ.',
            'cccd_front.mimes' => 'CCCD mặt trước chỉ chấp nhận định dạng JPG, PNG, WEBP.',
            'cccd_front.max' => 'CCCD mặt trước không được vượt quá 5MB.',
            'cccd_back.required' => 'Vui lòng tải lên mặt sau CCCD.',
            'cccd_back.file' => 'File CCCD mặt sau không hợp lệ.',
            'cccd_back.mimes' => 'CCCD mặt sau chỉ chấp nhận định dạng JPG, PNG, WEBP.',
            'cccd_back.max' => 'CCCD mặt sau không được vượt quá 5MB.',
            'cccd_front_2.required' => 'Khung giờ qua đêm cần khai báo lưu trú cho người đi cùng — vui lòng tải lên mặt trước CCCD người đi cùng.',
            'cccd_front_2.file' => 'File CCCD người đi cùng (mặt trước) không hợp lệ.',
            'cccd_front_2.mimes' => 'CCCD người đi cùng (mặt trước) chỉ chấp nhận định dạng JPG, PNG, WEBP.',
            'cccd_front_2.max' => 'CCCD người đi cùng (mặt trước) không được vượt quá 5MB.',
            'cccd_back_2.required' => 'Khung giờ qua đêm cần khai báo lưu trú cho người đi cùng — vui lòng tải lên mặt sau CCCD người đi cùng.',
            'cccd_back_2.file' => 'File CCCD người đi cùng (mặt sau) không hợp lệ.',
            'cccd_back_2.mimes' => 'CCCD người đi cùng (mặt sau) chỉ chấp nhận định dạng JPG, PNG, WEBP.',
            'cccd_back_2.max' => 'CCCD người đi cùng (mặt sau) không được vượt quá 5MB.',
            'buyerPhone2.required' => 'Khung giờ qua đêm cần khai báo lưu trú cho người đi cùng — vui lòng nhập số điện thoại người đi cùng.',
            'buyerPhone2.regex' => 'Số điện thoại người đi cùng không hợp lệ (VD: 0912345678)',
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