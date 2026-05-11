<?php

namespace Modules\BladeThemeV1\Livewire;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Livewire\Component;
use Modules\Page\Entities\ComponentConfiguration;
use Modules\Form\Entities\Form;
use Modules\BladeThemeV1\Traits\HandleDomainTrait;
use GuzzleHttp\Promise\Utils;
use Livewire\Attributes\Url;

class DomainLookupDetail extends Component
{
    use HandleDomainTrait;

    public string $tld;
    public array $domainData = [];
    public array $suggestedTlds = [];
    public array $tldGroups = [];
    public $selectedTlds = [];
    public $formId;
    public string $selectedDomain = '';
    public $formErrorMessage;
    public bool $loading = true;
    public bool $loadingRetry = true;
    public $domainBeingChecked = null;
    public array $domainRetryCache = [];
    public array $domainRetryData = [];
    public string $domain = '';
    protected $lastTldChange = null;

    #[Url(as: 'ten-mien')]
    public string $fullDomain = '';

    protected array $messages = [
        'fullDomain.required' => '* Vui lòng nhập tên miền.',
        'fullDomain.regex' => '* Tên miền không đúng định dạng hợp lệ.',
    ];

    protected array $rules = [
        'fullDomain' => 'required',
    ];

    public function mount(Request $request)
    {
        $this->fullDomain = $request->query('ten-mien', '');
        $this->validate();
        $this->selectedTlds = ['.com', '.vn', '.com.vn', '.net', '.info'];
        $this->tldGroups = $this->getTldGroups();
    }

    public function checkDomainAvailability()
    {
        $this->loading = true;
        // $this->validateFullDomain();
        $this->initDomainData();

        $client = new Client();
        $promises = [];

        if (!empty($this->tld)) {
            $promises[] = $this->fetchDomainsAsync("{$this->domain}.{$this->tld}", $client);
        }

        foreach ($this->suggestedTlds as $suggestedTld) {
            if ($suggestedTld === ".{$this->tld}") {
                continue;
            }
            $promises[] = $this->fetchDomainsAsync("{$this->domain}{$suggestedTld}", $client);
        }

        $results = Utils::all($promises)->wait();

        $parsedResults = [];

        foreach ($results as $response) {
            if (is_array($response) && isset($response['status']) && $response['status'] === 'false') {
                continue;
            }

            $parsedResults[] = $this->parseResponse($response)['data'];
        }

        $this->domainData = $parsedResults;

        $this->loading = false;
    }

    public function checkRetryDomain($domain)
    {
        if (isset($this->domainRetryCache[$domain])) {
            $this->domainRetryData = $this->domainRetryCache[$domain];
            $this->loadingRetry = false;
            return;
        }

        $client = new Client();
        $result = $this->fetchDomainsAsync($domain, $client, 40)->wait();
        $this->domainRetryCache[$domain] = $result;
        $this->domainRetryData = $result;
        $this->loadingRetry = false;
    }

    public function toggleTld($tld)
    {
        if ($this->lastTldChange && now()->diffInMilliseconds($this->lastTldChange) < 1000) {
            return;
        }


        $this->loading = true;
        $client = new Client();

        if (in_array($tld, $this->selectedTlds)) {
            $this->selectedTlds = array_filter($this->selectedTlds, fn($selectedTld) => $selectedTld !== $tld);

            $this->domainData = array_filter($this->domainData, function ($domainEntry) use ($tld) {
                return !str_ends_with($domainEntry['domainName'], $tld);
            });
        } else {
            $this->selectedTlds[] = $tld;
            $promise = $this->fetchDomainsAsync("{$this->domain}{$tld}", $client);
            $response = $promise->wait();
            $data = $this->parseResponse($response)['data'];
            $this->domainData[] = $data;
        }

        $this->loading = false;
    }

    public function initDomainData()
    {
        $this->domainData = [];
        $this->domain = $this->splitDomainTld($this->fullDomain)['domain'];
        $this->tld = $this->splitDomainTld($this->fullDomain)['tld'];
        $this->suggestedTlds = array_unique(array_merge($this->suggestedTlds, $this->selectedTlds));
    }

    public function parseResponse($response): array
    {
        if ($response instanceof \GuzzleHttp\Psr7\Response) {
            $bodyContent = (string) $response->getBody();
            $parsedData = json_decode($bodyContent, true);

            return [
                'status' => 'success',
                'data' => $parsedData
            ];
        }

        return [
            'status' => 'error',
            'message' => isset($response['message']) ? $response['message'] : 'Unknown error'
        ];
    }

    public function getDomainParts(string $fullDomain): array
    {
        return $this->splitDomainTld($fullDomain);
    }

    private function fetchForm()
    {
        $compConfig = ComponentConfiguration::with(['pageComponentConfigurationValues' => function ($query) {
            $query->select('comp_page_values.value', 'comp_page_values.type', 'comp_pages.created_at')
                ->latest()
                ->limit(1);
        }])
            ->where('name', 'form_consulting_domain')
            ->first();

        if (!$compConfig || $compConfig->pageComponentConfigurationValues->isEmpty()) {
            $this->formErrorMessage = 'Biểu mẫu chưa được tạo hoặc đã bị tắt.';
            return null;
        }

        if ($compConfig && $compConfig->pageComponentConfigurationValues->isNotEmpty()) {
            $latestValue = $compConfig->pageComponentConfigurationValues->first();
            $formId = $latestValue->pivot->value;
        }

        if (!empty($formId)) {
            return Form::with([
                'formFields',
                'formFields.fieldValues',
                'submissions',
                'emailSetting',
                'notification'
            ])->find($formId);
        }

        return null;
    }

    private function getLabelTldGroups(string $groupName): string
    {
        $labels = [
            'popular' => 'Tên miền phổ biến',
            'vietnam' => 'Tên miền Việt Nam',
            'international' => 'Tên miền quốc tế',
        ];

        return $labels[$groupName] ?? ucfirst($groupName);
    }

    public function render()
    {
        return view('bladethemev1::livewire.domain-lookup-detail', [
            'form' => $this->fetchForm(),
        ]);
    }
}
