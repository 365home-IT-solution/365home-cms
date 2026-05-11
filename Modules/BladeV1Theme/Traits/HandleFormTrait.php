<?php

namespace Modules\BladeThemeV1\Traits;

trait HandleFormTrait {
    public function formMessages($form)
    {
        $this->successMessage = $form->notification->success_message 
            ?? 'Xin cảm ơn, thông tin của bạn đã được gửi thành công! Chúng tôi sẽ liên hệ với bạn sớm nhất!';
        $this->errorMessage = $form->notification->error_message 
            ?? 'Đã có lỗi xảy ra khi gửi biểu mẫu. Vui lòng thử lại sau.';
    }

    protected function formFields($formFields)
    {
        $this->formFields = $formFields;

        foreach ($this->formFields as $field) {
            $this->formData[$field->name] = '';
        }
        
        $this->resetForm();
        $this->formFields = $formFields;
    }

    public function rules()
    {
        $rules = [];

        foreach ($this->formFields as $field) {
            $fieldRules = [];

            if ($field->is_required) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'nullable';
            }

            if ($field->type === 'email') {
                $fieldRules[] = 'email';
            }

            if ($field->type === 'tel') {
                $fieldRules[] = 'regex:/^0[1-9][0-9]{2}([- ]?[0-9]{3}){2}$/';
            }

            if ($field->min_length) {
                $fieldRules[] = 'min:' . $field->min_length;
            }

            if ($field->max_length) {
                $fieldRules[] = 'max:' . $field->max_length;
            }

            if ($field->type === 'file') {
                $fieldRules[] = 'file';
                $fieldRules[] = 'mimes:pdf,jpeg,png,jpg,xlsx,xls,doc,docx';
                $fieldRules[] = 'max:10240';
            }

            $rules['formData.' . $field->name] = $fieldRules;
        }

        return $rules;
    }

    protected function messages()
    {
        $messages = [];

        foreach ($this->formFields as $field) {
            $fieldName = $field->label ?? $field->name;

            if ($field->is_required) {
                $messages['formData.' . $field->name . '.required'] = $field->required_message ?? "Trường $fieldName là bắt buộc.";
            }

            if ($field->type === 'email') {
                $messages['formData.' . $field->name . '.email'] = $field->email_message ?? "Trường $fieldName phải là một địa chỉ email hợp lệ.";
            }

            if ($field->type === 'tel') {
                $messages['formData.' . $field->name . '.regex'] = $field->tel_message ?? "Trường $fieldName phải là số điện thoại hợp lệ (10 chữ số, bắt đầu bằng số 0).";
            }

            if ($field->min_length) {
                $messages['formData.' . $field->name . '.min'] = $field->min_length_message ?? "Trường $fieldName phải có ít nhất {$field->min_length} ký tự.";
            }

            if ($field->max_length) {
                $messages['formData.' . $field->name . '.max'] = $field->max_length_message ?? "Trường $fieldName không được vượt quá {$field->max_length} ký tự.";
            }

            if ($field->type === 'file') {
                $messages['formData.' . $field->name . '.file'] = "Trường $fieldName phải là một tệp hợp lệ.";
                $messages['formData.' . $field->name . '.mimes'] = "Trường $fieldName phải có định dạng: pdf, jpeg, png, jpg, xlsx, xls, doc, docx.";
                $messages['formData.' . $field->name . '.max'] = "Trường $fieldName không được vượt quá 10MB.";
            }
        }

        return $messages;
    }

    protected function isFormEmpty()
    {
        foreach ($this->formFields as $field) {
            $value = $this->formData[$field->name] ?? null;
            if ($value !== null && $value !== '' && $value !== []) {
                return false;
            }
        }
        return true;
    }
    protected function emptyForm()
    {
        if ($this->isFormEmpty()) {
            $this->dispatch('show-message', [
                'type' => 'error',
                'message' => 'Vui lòng nhập ít nhất một trường thông tin trước khi gửi.'
            ]);
            $this->loading = false;
            return true;
        }
        return false;
    }

    protected function sanitizeInput($input)
    {
        if (is_array($input)) {
            return array_map([$this, 'sanitizeInput'], $input);
        }
        if (preg_match('/<script.*?>.*?alert\s*\(.*?\).*?<\/script>/is', $input)) {
            return '<script>alert("Chúc bạn may mắn lần sau :)))");</script>';
        }
        $sanitized = strip_tags($input);

        $sanitized = htmlspecialchars($sanitized, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $sanitized = preg_replace('/[^\p{L}\p{N}\p{M}\p{P}\p{Zs}]/u', '', $sanitized);

        return $sanitized;
    }

    protected function sanitizeFormData()
    {
        foreach ($this->formFields as $field) {
            if (isset($this->formData[$field->name])) {
                if ($field->type !== 'file') {
                    $this->formData[$field->name] = $this->sanitizeInput($this->formData[$field->name]);
                }
            }
        }
    }

}
