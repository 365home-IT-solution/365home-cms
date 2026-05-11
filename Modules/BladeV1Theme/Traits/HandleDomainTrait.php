<?php

namespace Modules\BladeThemeV1\Traits;

use GuzzleHttp\Client;

trait HandleDomainTrait
{
    const API_ENDPOINT = 'https://whois.inet.vn/api/whois/domainspecify/';
    private int $timeout = 10;

    private function fetchDomainsAsync($fullDomain, Client $client, $timeout = null)
    {
        $timeout = $timeout ?? $this->timeout;
        $api_endpoint = self::API_ENDPOINT;

        return $client->getAsync("$api_endpoint" . "$fullDomain", [
            'timeout' => $timeout
        ])
            ->then(
                function ($response) use ($fullDomain) {
                    return $response;
                },
                function ($exception) use ($fullDomain) {
                    $errorMessage = $exception->getMessage();
                    if ($exception instanceof \GuzzleHttp\Exception\RequestException && $exception->getCode() === 28) {
                        $errorMessage = 'cURL error 28: Timeout';
                    }

                    return [
                        'status' => 'false',
                        'message' => $errorMessage
                    ];
                }
            );
    }

    public function splitDomainTld(string $domainInput): array
    {
        $domain = str_replace(' ', '', $domainInput);
        $domain = preg_replace('/[^\p{L}\p{N}\-.]/u', '', $domain);

        if (strpos($domain, '.') === false) {
            return ['domain' => $domain, 'tld' => ''];
        }

        $validTlds = $this->getIsValidTlds();
        usort($validTlds, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        $foundTld = '';
        foreach ($validTlds as $tld) {
            $tldPattern = preg_quote('.' . $tld) . '$';
            if (preg_match("/$tldPattern/i", $domain)) {
                $foundTld = $tld;
                break;
            }
        }

        if (empty($foundTld)) {
            return ['domain' => str_replace('.', '', $domain), 'tld' => ''];
        }

        $domainPart = substr($domain, 0, strlen($domain) - strlen($foundTld) - 1);        

        return ['domain' => str_replace('.', '', $domainPart), 'tld' => $foundTld];
    }

    private function isValidTlds(string $tld): bool
    {
        $validTlds = $this->getIsValidTlds();
        return in_array(strtolower($tld), $validTlds);
    }


    private function getIsValidTlds(): array
    {
        return [
            'com',
            'net',
            'org',
            'info',
            'biz',
            'name',
            'pro',
            'edu',
            'gov',
            'mil',
            'int',
            'top',
            'xyz',
            'site',
            'online',
            'tech',
            'store',
            'club',
            'website',
            'space',
            'shop',
            'app',
            'dev',
            'page',
            'vn',
            'us',
            'uk',
            'jp',
            'de',
            'fr',
            'cn',
            'ru',
            'it',
            'ca',
            'au',
            'br',
            'es',
            'nl',
            'kr',
            'se',
            'no',
            'id',
            'ph',
            'my',
            'th',
            'la',
            'com.vn',
            'net.vn',
            'org.vn',
            'edu.vn',
            'gov.vn',
            'int.vn',
            'ac.vn',
            'biz.vn',
            'info.vn',
            'pro.vn',
            'health.vn',
            'name.vn',
            'mil.vn',
            'web.vn',
            'art',
            'design',
            'digital',
            'email',
            'events',
            'finance',
            'group',
            'health',
            'life',
            'love',
            'media',
            'solutions',
            'today',
            'world',
            'asia',
            'tv',
            'co.uk',
            'mobi',
            'cc',
            'me',
        ];
    }

    private function getTldGroups(): array
    {
        return [
            'popular' => [
                '.vn' => 'Tên miền Việt Nam .vn',
                '.com.vn' => 'Tên miền Việt Nam .com.vn',
                '.net.vn' => 'Tên miền Việt Nam .net.vn',
                '.com' => 'Tên miền quốc tế .com',
                '.net' => 'Tên miền quốc tế .net',
                '.info' => 'Tên miền quốc tế .info',
                '.org' => 'Tên miền quốc tế .org',
                '.asia' => 'Tên miền quốc tế .asia',
            ],
            'vietnam' => [
                '.edu.vn' => 'Tên miền giáo dục .edu.vn',
                '.gov.vn' => 'Tên miền chính phủ .gov.vn',
                '.biz.vn' => 'Tên miền doanh nghiệp .biz.vn',
                '.org.vn' => 'Tên miền tổ chức .org.vn',
                '.name.vn' => 'Tên miền cá nhân .name.vn',
                '.info.vn' => 'Tên miền thông tin .info.vn',
                '.pro.vn' => 'Tên miền chuyên nghiệp .pro.vn',
                '.health.vn' => 'Tên miền y tế .health.vn',
            ],
            'international' => [
                '.biz' => 'Tên miền doanh nghiệp .biz',
                '.name' => 'Tên miền cá nhân .name',
                '.cc' => 'Tên miền quốc tế .cc',
                '.co' => 'Tên miền quốc tế .co',
                '.eu' => 'Tên miền châu Âu .eu',
                '.pro' => 'Tên miền chuyên nghiệp .pro',
                '.bz' => 'Tên miền quốc tế .bz',
                '.tv' => 'Tên miền truyền hình .tv',
                '.me' => 'Tên miền cá nhân .me',
                '.ws' => 'Tên miền quốc tế .ws',
                '.in' => 'Tên miền Ấn Độ .in',
                '.us' => 'Tên miền Hoa Kỳ .us',
                '.co.uk' => 'Tên miền Vương Quốc Anh .co.uk',
                '.mobi' => 'Tên miền di động .mobi',
                '.tel' => 'Tên miền liên lạc .tel',
            ],
        ];
    }
}
