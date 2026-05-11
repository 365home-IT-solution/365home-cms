<?php

namespace Modules\BladeThemeV1\Types\Footer;

use Modules\Form\Entities\Form;

class FooterNewsletterData
{
    public function __construct(
        public ?string $heading,
        public ?string $description,
        public null|int|Form $form,
    ){}

    public static function fromArray(array $data): self
    {
        return new self(
            heading: $data['heading'] ?? null,
            description: $data['description'] ?? null,
            form: $data['form'] ?? null,
        );
    }

    public function hasValidForm(): bool
    {
        return $this->form instanceof Form || (is_numeric($this->form) && $this->getForm() !== null);
    }

    public function getForm(): ?Form
    {
        if ($this->form instanceof Form) {
            return $this->form;
        }

        if (is_numeric($this->form)) {
            // Cache for ID-based lookups if needed
            return Form::find($this->form);
        }

        return null;
    }

    public function getFormId(): ?int
    {
        if ($this->form instanceof Form) {
            return $this->form->id;
        }

        return is_numeric($this->form) ? (int)$this->form : null;
    }
}
