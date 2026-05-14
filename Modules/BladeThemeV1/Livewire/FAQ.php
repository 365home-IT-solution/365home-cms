<?php

namespace Modules\BladeThemeV1\Livewire;

use Filament\Notifications\Collection;
use Livewire\Component;
use Modules\BladeThemeV1\Traits\HandleConfigTrait;
use Modules\QA\App\Models\QA;

class FAQ extends Component
{
    use HandleConfigTrait;
    protected $faqIds;

    public function mount($config): void
    {
        $this->setConfig($config);
        $this->faqIds = $this->getConfig('id_faq') ?? [];
    }

    protected function getFAQs()
    {
        $this->faqIds = [$this->faqIds];
        return QA::whereIn('id', $this->faqIds)->get();
    }


    protected function processFAQs(\Illuminate\Database\Eloquent\Collection $faqs): array
    {
        return $faqs->map(function ($faq) {
            $qaData = $faq->qa_data;

            if (is_array($qaData)) {
                return array_map(function ($qa) use ($faq) {
                    return array_merge([
                        'id' => $faq->id,
                        'name' => $faq->name,
                        'slug' => $faq->slug,
                        'categories' => $faq->categories,
                        'status' => $faq->status,
                        'created_at' => $faq->created_at,
                        'updated_at' => $faq->updated_at,
                    ], [
                        'question' => $qa['question'] ?? null,
                        'answer' => $qa['answer'] ?? null,
                        'is_visible' => $qa['is_visible'] ?? true,
                        'is_featured' => $qa['is_featured'] ?? false,
                    ]);
                }, $qaData);
            }

            return [
                [
                    'id' => $faq->id,
                    'name' => $faq->name,
                    'slug' => $faq->slug,
                    'categories' => $faq->categories,
                    'status' => $faq->status,
                    'created_at' => $faq->created_at,
                    'updated_at' => $faq->updated_at,
                    'question' => null,
                    'answer' => null,
                    'is_visible' => null,
                    'is_featured' => null,
                ],
            ];
        })->flatten(1)->toArray();
    }



    protected function getCategories(array $faqs): array
    {
        return collect($faqs)
            ->pluck('category')
            ->unique()
            ->filter()
            ->values()
            ->toArray();
    }

    public function sortFaqs($faqs, $sortBy) {
        switch ($sortBy) {
            case 'a-z':
                usort($faqs, fn($a, $b) => strcmp($a['question'], $b['question']));
                break;
            case 'z-a':
                usort($faqs, fn($a, $b) => strcmp($b['question'], $a['question']));
                break;
            case 'feature':
                usort($faqs, fn($a, $b) => $b['is_featured'] <=> $a['is_featured']);
                break;
        }
        return $faqs;
    }

    public function render()
    {
        $faqs = $this->getFAQs();
        $processedFaqs = $this->processFAQs($faqs);
        $sortBy = $this->getConfig('sort_by') ?? 'a-z';
        return view('bladethemev1::livewire.f-a-q', [
            'faqs' => $this->sortFaqs($processedFaqs, $sortBy),
            'showTableOfContents' => $this->getConfig('show_table_of_contents') ?? false,
            'enableSearch' => $this->getConfig('enable_search') ?? false,
            'layout' => $this->getConfig('layout') ?? 'accordion',
        ]);
    }
}