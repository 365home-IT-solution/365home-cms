<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\Form\Entities\Form;
use Modules\SettingCompany\Entities\Branch;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;

class Contact extends Component
{
    use HandleConfigTrait;

    public function mount($config) 
    {
        $this->setConfig($config);
    }

    public function getBusinessBranch() 
    {
        $businessBranch = !empty($this->config['business_branches']) 
            ? json_decode($this->config['business_branches'], true) 
            : null;

        if (!$businessBranch) {
            return null;
        }

        return array_values(array_map(function ($branches) {
            $branchRecord = Branch::find($branches['branch']);
            return [
                'icon' => $branches['icon'] ?? null,
                'title' => $branches['title'],
                'description' => $branches['description'] ?? null,
                'branch' => $branchRecord ? $branchRecord->toArray() : null,
            ];
        }, $businessBranch));
    }

    public function fetchForm()
    {
        return !empty($this->config['form'])
            ? Form::with([
                'formFields.fieldValues',
                'submissions',
                'emailSetting',
                'notification'
            ])->find($this->config['form'])
            : null;
    }

    public function processContactGroup(?array $contactGroup): ?array
    {
        if (!$contactGroup) {
            return [];
        }

        $phoneKeywords = [
            'số điện thoại', 'điện thoại', 'phone', 'phone number', 'hotline',
            'số liên lạc', 'contact', 'số máy', 'mobile', 'cellphone', 
            'số di động', 'telephone', 'customer service phone', 'số hỗ trợ', 
            'line điện thoại', 'support hotline', 'contact number', 'helpline', 
            'tổng đài', 'customer care phone', 'liên hệ qua', 'liên hệ'
        ];

        $mailKeywords = [
            'email', 'e-mail', 'mail', 'thư điện tử', 'địa chỉ email', 
            'email address', 'contact email', 'mailbox', 'email liên hệ', 
            'hộp thư', 'customer service email', 'support email', 'email hỗ trợ', 
            'email liên lạc', 'business email', 'corporate email', 'mailing address', 
            'liên hệ qua', 'liên hệ'
        ];

        foreach ($contactGroup as &$item) {
            $name = mb_strtolower($item['name'], 'UTF-8');
            $item['tel'] = null;
            $item['mail'] = null;

            foreach ($phoneKeywords as $keyword) {
                if (strpos($name, $keyword) !== false) {
                    $item['tel'] = 'tel:' . $item['value'];
                    break;
                }
            }

            foreach ($mailKeywords as $keyword) {
                if (strpos($name, $keyword) !== false) {
                    $item['mail'] = 'mailto:' . $item['value'];
                    break;
                }
            }
        }

        return $contactGroup;
    }

    public function render()
    {
        $form = $this->fetchForm();
        $iframeCode = $this->getConfig('iframe');
        $contactGroup = $this->processContactGroup($this->getConfig('contact_group') ?? []);
        $branches = $this->getBusinessBranch();

        return view('bladethemev1::livewire.contact', [
            'form' => $form,
            'iframeCode' => $iframeCode,
            'contactGroup' => $contactGroup,
            'branches' => $branches,
        ]);
    }
}
