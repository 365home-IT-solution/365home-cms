<?php

namespace Modules\BladeThemeV1\Livewire;

use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Modules\Form\Entities\EmailSetting;
use Modules\Form\Entities\Form;
use Modules\Form\Entities\FormFieldValue;
use Modules\Form\Entities\FormSubmission;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;
use Modules\BladeThemeV1\Traits\HandleFormTrait;

class FormNewsletter extends Component
{
    use WithFileUploads, HandleConfigTrait, HandleFormTrait;

    public Form $form;
    public $formFields = [];
    public $formData = [];
    public $successMessage;
    public $errorMessage;
    public $loading = false;
    public $title = '';

    public function mount($form): void
    {
        $this->form = $form;
        $this->formMessages($form);
        if (isset($form->formFields)) {
            $this->formFields($form->formFields);
        }
    }

    public function submitForm()
    {
        $this->loading = true;
        
        if (isset($this->form->name)) {
            $this->formData['form_info'] = $this->form->name . ' - ' . $this->title;
        } else {
            return;
        }

        $this->validate($this->rules(), $this->messages());

        //không nhập form
        if ($this->emptyForm()) {
            return;
        }
        //bảo mật script
        $this->sanitizeFormData();

        try {
            $submission = FormSubmission::create([
                'form_id' => $this->form->id,
                'is_viewed' => false,
            ]);

            foreach ($this->formFields as $field) {
                FormFieldValue::create([
                    'form_submission_id' => $submission->id,
                    'form_field_id' => $field->id,
                    'value' => $this->formData[$field->name],
                ]);
            }

            $this->sendEmail($submission);

            $this->formData = array_fill_keys(array_keys($this->formData), '');

            $this->dispatch('notify', [
                'message' => $this->successMessage,
                'type' => 'success',
            ]);
        } catch (\Exception $e) {
            $this->dispatch('notify', [
                'message' => $this->errorMessage,
                'type' => 'error',
            ]);
        }

        $this->loading = false;
    }

    protected function sendEmail($submission)
    {
        $emailSetting = EmailSetting::where('form_id', $this->form->id)->first();

        if ($emailSetting) {
            $toEmail = $emailSetting->to_email;
            $fromEmail = $emailSetting->from_email;
            $subject = $emailSetting->subject;
            $messageBody = $emailSetting->message_body;

            foreach ($this->formFields as $field) {
                $key = $field->name;
                $value = $this->formData[$key] ?? '';
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $messageBody = str_replace("{{ $key }}", $value, $messageBody);
            }

            $appUrl = config('app.url');
            $messageBody .= "\n\nĐược gửi từ website: [{$appUrl}]";

            $subject = "[Form: {$this->form->name}] " . $subject;
            $messageBody = "Form Name: {$this->form->name}\n\n" . $messageBody;
            $messageBody = preg_replace('/C:\\\\Users\\\\[^\\\\]+\\\\AppData\\\\Local\\\\Temp\\\\[a-zA-Z0-9]+\.tmp/', '', $messageBody);

            $mailerType = $emailSetting->mailer_type ?? config('mail.default');

            try {
                Mail::mailer($mailerType)->send([], [], function ($message) use ($toEmail, $fromEmail, $subject, $messageBody) {
                    $message->to($toEmail)
                        ->from($fromEmail)
                        ->subject($subject)
                        ->html(nl2br($messageBody));

                    foreach ($this->formFields as $field) {
                        $file = $this->formData[$field->name];
                        if ($file instanceof \Illuminate\Http\UploadedFile && $file->isValid()) {
                            $fileName = $file->getClientOriginalName();

                            $message->attachData(file_get_contents($file->getRealPath()), $fileName, [
                                'mime' => $file->getMimeType(),
                            ]);

                            $this->formData[$field->name] = $fileName;
                        }
                    }
                });
            } catch (\Exception $e) {
                \Log::error('Email sending failed', ['error' => $e->getMessage()]);
            }
        } else {
            \Log::error('No email settings found for form', ['form_id' => $this->form->id]);
        }
    }

    #[On('setNameForm')]
    public function setNameForm($name)
    {
        $this->title = $name;
    }

    #[On('resetForm')]
    public function resetForm()
    {
        $this->formData = collect($this->formFields)->mapWithKeys(function ($field) {
            return [$field->name => ''];
        })->toArray();

        $this->resetValidation();
        $this->loading = false;
    }

    public function render()
    {
        return view('bladethemev1::livewire.form-newsletter');
    }
}
