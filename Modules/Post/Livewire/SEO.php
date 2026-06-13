<?php

namespace Modules\Post\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;
use Illuminate\Support\Str;
use Modules\Post\Entities\Post;

class SEO extends Component
{
    public $content = '';
    public $title = '';
    public $seoTitle = '';
    public $description = '';
    public $focusKeyword = [];
    public $slug = '';
    public $score = 0;
    public $analyses = [];
    public $advancedAnalyses = [];
    public $readabilityAnalyses = [];
    public $secondaryKeywordAnalyses = [];
    public $style = '';

    public function mount($initialData = null, $record = null)
    {
        $this->initializeAnalyses();

        if ($record) {
            $this->loadRecordData($record);
            $this->analyzeSEO($record['id'] ?? null);
        }
    }

    protected function initializeAnalyses()
    {
        $this->analyses = $this->getDefaultAnalyses();
        $this->advancedAnalyses = $this->getDefaultAdvancedAnalyses();
        $this->readabilityAnalyses = $this->getDefaultReadabilityAnalyses();
        $this->secondaryKeywordAnalyses = [];
    }

    protected function getDefaultAnalyses()
    {
        return [
            'title' => $this->defaultAnalysis('Chưa phân tích tiêu đề'),
            'description' => $this->defaultAnalysis('Chưa phân tích mô tả'),
            'url' => $this->defaultAnalysis('Chưa phân tích URL'),
            'keywordPlacement' => $this->defaultAnalysis('Chưa phân tích vị trí từ khóa'),
            'contentLength' => $this->defaultAnalysis('Chưa phân tích độ dài nội dung'),
        ];
    }

    protected function getDefaultAdvancedAnalyses()
    {
        return [
            'subheadings' => $this->defaultAnalysis('Chưa phân tích tiêu đề phụ'),
            'images' => $this->defaultAnalysis('Chưa phân tích hình ảnh'),
            'keywordDensity' => $this->defaultAnalysis('Chưa phân tích mật độ từ khóa'),
            'urlLength' => $this->defaultAnalysis('Chưa phân tích độ dài URL'),
            'externalLinks' => $this->defaultAnalysis('Chưa phân tích liên kết ngoài'),
            'internalLinks' => $this->defaultAnalysis('Chưa phân tích liên kết nội bộ'),
            'keywordHistory' => $this->defaultAnalysis('Chưa kiểm tra lịch sử từ khóa'),
        ];
    }

    protected function getDefaultReadabilityAnalyses()
    {
        return [
            'paragraphLength' => $this->defaultAnalysis('Chưa phân tích độ dài đoạn văn'),
            'mediaContent' => $this->defaultAnalysis('Chưa phân tích nội dung đa phương tiện'),
        ];
    }

    protected function defaultAnalysis($message)
    {
        return [
            'score' => 0,
            'message' => $message,
            'status' => 'pending',
        ];
    }

    protected function loadRecordData($record)
    {
        $this->content = $record['content'] ?? $this->content;
        $this->title = $record['title'] ?? $this->title;
        $this->description = $record['seo_description'] ?? $this->description;
        $this->slug = $record['slug'] ?? $this->slug;
        $this->focusKeyword = $this->extractFocusKeywords($record['seo_keywords'] ?? '');
    }

    protected function extractFocusKeywords($keywords)
    {
        if (is_array($keywords)) {
            return array_filter(array_map('trim', $keywords));
        }

        return array_filter(array_map('trim', explode(',', $keywords)));
    }

    #[On('seon')]
    public function updateContent($data)
    {
        $this->initializeAnalyses();
        $this->content = $data['content'] ?? $this->content;
        $this->title = $data['title'] ?? $this->title;
        $this->description = $data['description'] ?? $this->description;
        $this->slug = $data['url'] ?? $this->slug;
        $this->focusKeyword = $this->extractFocusKeywords($data['focusKeyword'] ?? '');

        $this->analyzeSEO($data['id'] ?? null);
    }

    protected function analyzeSEO($currentRecordId = null)
    {
        // Basic SEO
        $this->analyzeTitle();
        $this->analyzeDescription();
        $this->analyzeURL();
        $this->analyzeKeywordPlacement();
        $this->analyzeContentLength();

        // Advanced SEO
        $this->analyzeAdvancedSEO($currentRecordId);

        // Readability
        $this->analyzeReadability();

        // Secondary keywords
        $this->analyzeSecondaryKeywords();

        // Calculate overall score
        $this->score = $this->calculateOverallScore();
    }

    protected function calculateOverallScore()
    {
        $weights = $this->getAnalysisWeights();

        $totalScore = 0;
        $totalWeight = 0;

        foreach (['analyses', 'advancedAnalyses', 'readabilityAnalyses'] as $category) {
            foreach ($weights[$category] as $key => $weight) {
                if (isset($this->{$category}[$key]) && $this->{$category}[$key]['status'] !== 'pending') {
                    $totalScore += ($this->{$category}[$key]['score'] * $weight);
                    $totalWeight += $weight;
                }
            }
        }

        return $totalWeight > 0 ? round($totalScore / $totalWeight) : 0;
    }

    protected function getAnalysisWeights()
    {
        return [
            // Basic SEO (50%)
            'analyses' => [
                'title' => 15,
                'description' => 9,
                'url' => 8,
                'keywordPlacement' => 9,
                'contentLength' => 7,
            ],
            // Advanced SEO (30%)
            'advancedAnalyses' => [
                'subheadings' => 5,
                'images' => 5,
                'keywordDensity' => 10,
                'urlLength' => 3,
                'externalLinks' => 2,
                'internalLinks' => 2,
                'keywordHistory' => 5,
            ],
            // Readability (20%)
            'readabilityAnalyses' => [
                'paragraphLength' => 7,
                'mediaContent' => 8,
            ],
        ];
    }

    protected function getPrimaryKeyword()
    {
        $primaryKeyword = trim($this->focusKeyword[0] ?? '');

        return $primaryKeyword !== '' ? $primaryKeyword : null;
    }

    protected function getSecondaryKeywords()
    {
        return array_map('trim', array_slice($this->focusKeyword, 1));
    }

    protected function containsKeyword(string $field): bool
    {
        $primaryKeyword = $this->getPrimaryKeyword();
        if (empty($primaryKeyword) || empty($field)) {
            return false;
        }

        // Kiểm tra xem field có phải là URL slug không
        if ($this->isSlug($field)) {
            // Nếu field là slug, chuyển keyword sang slug để so sánh
            $normalizedKeyword = $this->slugify($primaryKeyword);
            $normalizedField = $this->cleanText($field, [
                'lowercase' => true,
                'normalize_whitespace' => true
            ]);
            return str_contains($normalizedField, $normalizedKeyword);
        }

        // Nếu không phải slug, chuẩn hóa cả field và keyword
        $normalizedField = $this->cleanText($field, [
            'decode_entities' => true,
            'normalize_whitespace' => true,
            'lowercase' => true,
            'normalize_unicode' => true
        ]);

        $normalizedKeyword = $this->cleanText($primaryKeyword, [
            'decode_entities' => true,
            'normalize_whitespace' => true,
            'lowercase' => true,
            'normalize_unicode' => true
        ]);

        // So sánh cả phiên bản có dấu và không dấu
        return str_contains($normalizedField, $normalizedKeyword) ||
            str_contains(
                $this->removeVietnameseTones($normalizedField),
                $this->removeVietnameseTones($normalizedKeyword)
            );
    }

    protected function isSlug(string $str): bool
    {
        return (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $str);
    }

    protected function slugify(string $str): string
    {
        // Bước 1: Chuẩn hóa chuỗi
        $str = $this->cleanText($str, [
            'decode_entities' => true,
            'normalize_whitespace' => true,
            'lowercase' => true,
            'normalize_unicode' => true
        ]);

        // Bước 2: Bỏ dấu tiếng Việt
        $str = $this->removeVietnameseTones($str);

        // Bước 3: Chuyển đổi sang slug
        $str = preg_replace('/[^a-z0-9\s-]/', '', $str); // Chỉ giữ lại chữ cái, số và dấu gạch ngang
        $str = preg_replace('/\s+/', '-', $str);         // Chuyển khoảng trắng thành dấu gạch ngang
        $str = preg_replace('/-+/', '-', $str);          // Loại bỏ dấu gạch ngang trùng lặp
        $str = trim($str, '-');                          // Xóa dấu gạch ngang ở đầu và cuối

        return $str;
    }

    protected function cleanText(string $str, array $options = []): string
    {
        $defaultOptions = [
            'decode_entities' => true,      // Decode HTML entities
            'normalize_whitespace' => true, // Chuẩn hóa khoảng trắng
            'lowercase' => false,           // Chuyển về chữ thường
            'normalize_unicode' => false,   // Chuẩn hóa Unicode
            'remove_special_chars' => false // Loại bỏ ký tự đặc biệt
        ];

        $options = array_merge($defaultOptions, $options);

        if ($options['decode_entities']) {
            $str = html_entity_decode($str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        if ($options['normalize_unicode']) {
            // Sử dụng mã Unicode trực tiếp để tránh lỗi syntax
            $search = [
                "\u{2032}", // ′ (prime)
                "\u{2018}", // ' (left single quotation mark)
                "\u{2019}", // ' (right single quotation mark)
                "\u{201C}", // " (left double quotation mark)
                "\u{201D}", // " (right double quotation mark)
                "\u{2033}", // ″ (double prime)
                "\u{201F}", // ‟ (double high-reversed-9 quotation mark)
                "\u{201E}", // „ (double low-9 quotation mark)
                "\u{2015}", // ― (horizontal bar)
                "\u{2013}", // – (en dash)
                "\u{2010}", // ‐ (hyphen)
                "\u{2011}"  // ‑ (non-breaking hyphen)
            ];
            $replace = ["'", "'", "'", '"', '"', '"', '"', '"', "-", "-", "-", "-"];
            $str = str_replace($search, $replace, $str);
        }

        if ($options['normalize_whitespace']) {
            $str = preg_replace('/\s+/', ' ', $str);
            $str = trim($str);
        }

        if ($options['lowercase']) {
            $str = mb_strtolower($str, 'UTF-8');
        }

        if ($options['remove_special_chars']) {
            $str = preg_replace('/[^a-zA-Z0-9\s\.,!?()àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđĐ-]/u', '', $str);
        }

        return $str;
    }

    protected function removeVietnameseTones(string $str): string
    {
        $patterns = [
            // Chữ cái
            '/[àáạảãâầấậẩẫăằắặẳẵ]/u' => 'a',
            '/[èéẹẻẽêềếệểễ]/u' => 'e',
            '/[ìíịỉĩ]/u' => 'i',
            '/[òóọỏõôồốộổỗơờớợởỡ]/u' => 'o',
            '/[ùúụủũưừứựửữ]/u' => 'u',
            '/[ỳýỵỷỹ]/u' => 'y',
            '/[đ]/u' => 'd',
            // Chữ hoa
            '/[ÀÁẠẢÃÂẦẤẬẨẪĂẰẮẶẲẴ]/u' => 'A',
            '/[ÈÉẸẺẼÊỀẾỆỂỄ]/u' => 'E',
            '/[ÌÍỊỈĨ]/u' => 'I',
            '/[ÒÓỌỎÕÔỒỐỘỔỖƠỜỚỢỞỠ]/u' => 'O',
            '/[ÙÚỤỦŨƯỪỨỰỬỮ]/u' => 'U',
            '/[ỲÝỴỶỸ]/u' => 'Y',
            '/[Đ]/u' => 'D',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $str);
    }


    protected function analyzeKeywordUsage($keyword, $isPrimary = false)
    {
        $score = 0;
        $multiplier = $isPrimary ? 1 : 0.5;

        if (str_contains(strtolower($this->title), strtolower($keyword))) {
            $score += 20 * $multiplier;
        }

        if (str_contains(strtolower($this->description), strtolower($keyword))) {
            $score += 20 * $multiplier;
        }

        if (str_contains(strtolower($this->slug), strtolower($keyword))) {
            $score += 20 * $multiplier;
        }

        if (str_contains(strtolower(strip_tags($this->content)), strtolower($keyword))) {
            $score += 40 * $multiplier;
        }

        return $score;
    }

    protected function analyzeTitle()
    {
        if (empty($this->title)) {
            $this->analyses['title'] = [
                'score' => 0,
                'message' => 'Thêm từ khóa chính vào tiêu đề SEO',
                'status' => 'error'
            ];
            return;
        }

        $hasPrimaryKeyword = $this->containsKeyword($this->title);
        $secondaryKeywords = $this->getSecondaryKeywords();
        $hasSecondaryKeyword = false;
        foreach ($secondaryKeywords as $keyword) {
            if (str_contains(strtolower($this->title), strtolower($keyword))) {
                $hasSecondaryKeyword = true;
                break;
            }
        }

        $length = Str::length($this->title);
        $score = 0;
        $message = '';
        $status = '';

        if ($length > 60) {
            $score = 33;
            $message = 'Tiêu đề quá dài (tối đa 60 ký tự)';
            $status = 'warning';
        } else {
            if ($hasPrimaryKeyword) {
                $score = 100;
                $message = 'Tuyệt vời! Bạn đã sử dụng từ khóa chính trong tiêu đề SEO';
                $status = 'success';
            } elseif ($hasSecondaryKeyword) {
                $score = 50;
                $message = 'Tiêu đề thiếu từ khóa chính';
                $status = 'error';
            } else {
                $score = 0;
                $message = 'Tiêu đề chưa có từ khóa nào';
                $status = 'error';
            }
        }

        $this->analyses['title'] = compact('score', 'message', 'status');
    }

    protected function analyzeDescription()
    {
        if (empty($this->description)) {
            $this->analyses['description'] = [
                'score' => 0,
                'message' => 'Thêm từ khóa vào thẻ mô tả meta SEO',
                'status' => 'error'
            ];
            return;
        }

        $hasPrimaryKeyword = $this->containsKeyword($this->description);
        $secondaryKeywords = $this->getSecondaryKeywords();
        $hasSecondaryKeyword = false;
        foreach ($secondaryKeywords as $keyword) {
            if (str_contains(strtolower($this->description), strtolower($keyword))) {
                $hasSecondaryKeyword = true;
                break;
            }
        }

        $length = Str::length($this->description);
        $score = 0;
        $status = '';
        $message = '';

        if ($length < 120 || $length > 160) {
            $score = 33;
            $message = "Độ dài meta description không phù hợp (nên từ 120-160 ký tự)";
            $status = 'warning';
        } else {
            if ($hasPrimaryKeyword) {
                $score = 100;
                $message = "Meta description tốt: có từ khóa chính và độ dài phù hợp";
                $status = 'success';
            } elseif ($hasSecondaryKeyword) {
                $score = 50;
                $message = "Meta description thiếu từ khóa chính";
                $status = 'error';
            } else {
                $score = 0;
                $message = "Meta description chưa có từ khóa nào";
                $status = 'error';
            }
        }

        $this->analyses['description'] = compact('score', 'message', 'status');
    }

    protected function analyzeURL()
    {
        if (empty($this->slug)) {
            $this->analyses['url'] = [
                'score' => 0,
                'message' => 'Sử dụng từ khóa chính trong URL',
                'status' => 'error'
            ];
            return;
        }

        $hasPrimaryKeyword = $this->containsKeyword($this->slug);
        $secondaryKeywords = $this->getSecondaryKeywords();
        $hasSecondaryKeyword = false;
        foreach ($secondaryKeywords as $keyword) {
            if (str_contains(strtolower($this->slug), strtolower($keyword))) {
                $hasSecondaryKeyword = true;
                break;
            }
        }

        if ($hasPrimaryKeyword) {
            $score = 100;
            $message = 'URL tốt: có chứa từ khóa chính';
            $status = 'success';
        } elseif ($hasSecondaryKeyword) {
            $score = 60;
            $message = 'URL thiếu từ khóa chính';
            $status = 'error';
        } else {
            $score = 0;
            $message = 'URL chưa có từ khóa nào';
            $status = 'error';
        }

        $this->analyses['url'] = compact('score', 'message', 'status');
    }

    protected function analyzeKeywordPlacement()
    {
        if (empty($this->content) || empty($this->focusKeyword)) {
            $this->analyses['keywordPlacement'] = [
                'score' => 0,
                'message' => 'Không thể phân tích vị trí từ khóa (thiếu từ khóa chính trong nội dung)',
                'status' => 'error',
            ];
            return;
        }

        $plainContent = $this->cleanText($this->content);
        $totalWords = str_word_count($plainContent);

        if ($totalWords > 300) {
            $wordsToCheck = ceil($totalWords * 0.1); // Lấy 10%
            $contentToAnalyze = implode(' ', array_slice(explode(' ', $plainContent), 0, $wordsToCheck));
        } else {
            $contentToAnalyze = implode(' ', array_slice(explode(' ', $plainContent), 0, 300));
        }

        $hasPrimaryKeyword = $this->containsKeyword($contentToAnalyze);

        if ($hasPrimaryKeyword) {
            $score = 100;
            $message = 'Tuyệt vời! Từ khóa chính xuất hiện trong 10% nội dung đầu tiên';
            $status = 'success';
        } else {
            $score = 0;
            $message = 'Từ khoá chính không xuất hiện ở đầu nội dung của bạn';
            $status = 'error';
        }

        $this->analyses['keywordPlacement'] = compact('score', 'message', 'status');
    }


    protected function analyzeKeywordDensity()
    {
        if (empty($this->content) || empty($this->focusKeyword)) {
            $this->advancedAnalyses['keywordDensity'] = [
                'score' => 0,
                'message' => 'Mật độ từ khóa là 0. Hãy nhắm đến mật độ từ khóa khoảng 1%.',
                'status' => 'error'
            ];
            return;
        }

        $plainContent = $this->cleanText($this->content);
        $wordCount = str_word_count($plainContent);

        // Phân tích từ khóa chính note
        $primaryKeyword = $this->getPrimaryKeyword();
        $primaryCount = substr_count(strtolower($plainContent), strtolower($primaryKeyword));
        $primaryDensity = $wordCount > 0 ? ($primaryCount / $wordCount) * 100 : 0;

        // Đánh giá mật độ từ khóa chính
        if ($primaryDensity < 0.5) {
            $status = 'error';
            $message = "Mật độ từ khóa chính quá thấp (" . round($primaryDensity, 2) . "%)";
            $score = 0;
        } elseif ($primaryDensity >= 0.5 && $primaryDensity < 0.7) {
            $status = 'warning';
            $message = "Mật độ từ khóa chính hơi thấp (" . round($primaryDensity, 2) . "%)";
            $score = 33;
        } elseif ($primaryDensity >= 0.7 && $primaryDensity < 1) {
            $status = 'warning';
            $message = "Mật độ từ khóa chính chấp nhận được nhưng có thể cải thiện (" . round($primaryDensity, 2) . "%)";
            $score = 66;
        } elseif ($primaryDensity >= 1 && $primaryDensity <= 2.5) {
            $status = 'success';
            $message = "Mật độ từ khóa chính tối ưu (" . round($primaryDensity, 2) . "%)";
            $score = 100;
        } elseif ($primaryDensity > 2.5) {
            $status = 'error';
            $message = "Mật độ từ khóa chính quá cao (" . round($primaryDensity, 2) . "%)";
            $score = 0;
        }


        $this->advancedAnalyses['keywordDensity'] = compact('score', 'message', 'status');
    }

    protected function analyzeSubheadings()
    {
        if (empty($this->content)) {
            $this->advancedAnalyses['subheadings'] = [
                'score' => 0,
                'message' => 'Sử dụng từ khóa chính trong các tiêu đề phụ như H2, H3,...',
                'status' => 'error'
            ];
            return;
        }

        preg_match_all('/<h[2-6][^>]*>(.*?)<\/h[2-6]>/i', $this->content, $matches);
        $headings = $matches[1] ?? [];

        if (empty($headings)) {
            $this->advancedAnalyses['subheadings'] = [
                'score' => 0,
                'message' => 'Sử dụng từ khóa chính trong các tiêu đề phụ như H2, H3,...',
                'status' => 'error'
            ];
            return;
        }

        $hasPrimaryKeyword = false;
        $headingsWithKeywords = 0;

        foreach ($headings as $heading) {
            if ($this->containsKeyword($heading)) {
                $hasPrimaryKeyword = true;
                $headingsWithKeywords++;
            }
        }

        $totalHeadings = count($headings);
        $keywordPercentage = ($headingsWithKeywords / $totalHeadings) * 100;

        if ($hasPrimaryKeyword) {
            if ($keywordPercentage >= 30) {
                $score = 100;
                $message = "Tuyệt vời! Từ khóa chính xuất hiện trong tiêu đề phụ";
                $status = 'success';
            } else {
                $score = 80;
                $message = "Tốt! Có từ khóa chính trong tiêu đề phụ nhưng có thể tối ưu thêm";
                $status = 'success';
            }
        } else {
            $score = 0;
            $message = "Sử dụng từ khóa chính trong các tiêu đề phụ như H2, H3,...";
            $status = 'error';
        }

        $this->advancedAnalyses['subheadings'] = compact('score', 'message', 'status');
    }

    //note
    protected function analyzeImages()
    {
        if (empty($this->content)) {
            $this->advancedAnalyses['images'] = [
                'score' => 0,
                'message' => 'Thêm hình ảnh với từ khóa chính làm văn bản thay thế',
                'status' => 'error'
            ];
            return;
        }

        preg_match_all('/<img[^>]+>/i', $this->content, $matches);
        $images = $matches[0] ?? [];

        if (empty($images)) {
            $this->advancedAnalyses['images'] = [
                'score' => 0,
                'message' => 'Thêm hình ảnh với từ khóa chính làm văn bản thay thế',
                'status' => 'error'
            ];
            return;
        }

        $imagesWithPrimaryKeyword = 0;
        $imagesWithAlt = 0;
        $totalImages = count($images);

        foreach ($images as $img) {
            preg_match('/alt=["\'](.*?)["\']/i', $img, $alt);
            if (!empty($alt[1])) {
                $imagesWithAlt++;
                if ($this->containsKeyword($alt[1])) {
                    $imagesWithPrimaryKeyword++;
                }
            }
        }

        if ($imagesWithAlt === 0) {
            $score = 0;
            $message = "Thêm hình ảnh với từ khóa chính làm văn bản thay thế";
            $status = 'error';
        } elseif ($imagesWithPrimaryKeyword > 0) {
            $score = 100;
            $message = "Tuyệt vời! Có {$imagesWithPrimaryKeyword} hình ảnh chứa từ khóa chính trong alt text";
            $status = 'success';
        } else {
            $score = 0;
            $message = "Các hình ảnh có alt text nhưng không chứa từ khóa chính";
            $status = 'error';
        }

        $this->advancedAnalyses['images'] = compact('score', 'message', 'status');
    }


    protected function analyzeExternalLinks()
    {
        if (empty($this->content)) {
            $this->advancedAnalyses['externalLinks'] = [
                'score' => 0,
                'message' => 'Liên kết đến các tài nguyên bên ngoài',
                'status' => 'error'
            ];
            return;
        }

        preg_match_all('/<a[^>]+href=["\'](https?:\/\/[^"\']+)["\'][^>]*>(.*?)<\/a>/i', $this->content, $matches);
        $externalLinks = $matches[1] ?? [];
        $count = count($externalLinks);

        $score = 0;
        $message = '';
        $status = '';

        if ($count === 0) {
            $score = 0;
            $message = 'Liên kết đến các tài nguyên bên ngoài';
            $status = 'error';
        } else {
            $score = 100;
            $message = "Tuyệt vời! Bạn đang liên kết đến các tài nguyên bên ngoài.";
            $status = 'success';
        }

        $this->advancedAnalyses['externalLinks'] = compact('score', 'message', 'status');
    }

    protected function analyzeInternalLinks()
    {
        if (empty($this->content)) {
            $this->advancedAnalyses['internalLinks'] = [
                'score' => 0,
                'message' => 'Thêm liên kết nội bộ trong nội dung của bạn.',
                'status' => 'error'
            ];
            return;
        }

        $baseUrl = parse_url(config('app.url'), PHP_URL_HOST);
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/i', $this->content, $matches);

        $internalLinks = [];
        $linkTexts = [];

        // Lọc liên kết nội bộ
        for ($i = 0; $i < count($matches[1]); $i++) {
            $url = $matches[1][$i];
            if (
                str_starts_with($url, '/') ||
                str_contains(parse_url($url, PHP_URL_HOST) ?? '', $baseUrl)
            ) {
                $internalLinks[] = $url;
                $linkTexts[] = strip_tags($matches[2][$i]);
            }
        }

        $count = count($internalLinks);

        $score = 0;
        $message = '';
        $status = '';

        if ($count === 0) {
            $score = 0;
            $message = 'Thêm liên kết nội bộ trong nội dung của bạn.';
            $status = 'error';
        } else {
            $score = 100;
            $message = "Bạn đang liên kết đến các tài nguyên khác trên trang web của mình, điều này thật tuyệt.";
            $status = 'success';
        }

        $this->advancedAnalyses['internalLinks'] = compact('score', 'message', 'status');
    }

    protected function analyzeParagraphLength()
    {
        if (empty($this->content)) {
            $this->readabilityAnalyses['paragraphLength'] = [
                'score' => 0,
                'message' => 'Thêm các văn bản ngắn gọn để cải thiện trải nghiệm người dùng.',
                'status' => 'error'
            ];
            return;
        }

        preg_match_all('/<p[^>]*>(.*?)<\/p>|(?:\n\s*){2,}/s', $this->content, $matches);
        $paragraphs = array_filter(array_map('strip_tags', $matches[0]));

        if (empty($paragraphs)) {
            $this->readabilityAnalyses['paragraphLength'] = [
                'score' => 0,
                'message' => 'Thêm các văn bản ngắn gọn để cải thiện trải nghiệm người dùng.',
                'status' => 'error'
            ];
            return;
        }

        $totalParagraphs = count($paragraphs);
        $veryLongParagraphs = 0;
        $paragraphDetails = [];

        foreach ($paragraphs as $index => $paragraph) {
            $wordCount = str_word_count($paragraph);
            $paragraphDetails[] = [
                'index' => $index + 1,
                'wordCount' => $wordCount,
                'length' => strlen($paragraph)
            ];

            if ($wordCount > 150) {
                $veryLongParagraphs++;
            }
        }


        if ($veryLongParagraphs > 0) {
            $score = 0;
            $message = "Có $veryLongParagraphs/$totalParagraphs đoạn văn rất dài (>150 từ). Nên chia nhỏ để dễ đọc hơn.";
            $status = 'error';
        } else {
            $score = 100;
            $message = "Độ dài các đoạn văn phù hợp, dễ đọc.";
            $status = 'success';
        }

        $this->readabilityAnalyses['paragraphLength'] = [
            'score' => $score,
            'message' => $message,
            'status' => $status,
            'details' => $paragraphDetails // Chi tiết 
        ];
    }


    protected function analyzeMediaContent()
    {
        if (empty($this->content)) {
            $this->readabilityAnalyses['mediaContent'] = [
                'score' => 0,
                'message' => 'Thêm hình ảnh hoặc video để nội dung hấp dẫn hơn',
                'status' => 'error',
            ];
            return;
        }

        preg_match_all('/<img[^>]+>/i', $this->content, $imageMatches);
        $imageCount = count($imageMatches[0]);

        preg_match_all('/<iframe[^>]+>|<video[^>]+>/i', $this->content, $videoMatches);
        $videoCount = count($videoMatches[0]);

        $totalMediaCount = $imageCount + $videoCount;

        if ($totalMediaCount === 0) {
            $score = 0;
            $message = "Không tìm thấy nội dung đa phương tiện. Nên thêm hình ảnh/video để tăng tính hấp dẫn.";
            $status = 'error';
        } elseif ($totalMediaCount === 1) {
            $score = 25;
            $message = "Có 1 hình ảnh hoặc video. Nên bổ sung thêm để nội dung phong phú hơn.";
            $status = 'warning';
        } elseif ($totalMediaCount === 2) {
            $score = 50;
            $message = "Có 2 hình ảnh hoặc video. Nên bổ sung thêm để tăng sức hút.";
            $status = 'warning';
        } elseif ($totalMediaCount === 3) {
            $score = 75;
            $message = "Có 3 hình ảnh hoặc video. Nội dung khá tốt nhưng vẫn có thể cải thiện.";
            $status = 'success';
        } else {
            $score = 100;
            $message = "Tốt! Có $totalMediaCount hình ảnh hoặc video. Nội dung rất hấp dẫn.";
            $status = 'success';
        }

        $this->readabilityAnalyses['mediaContent'] = compact('score', 'message', 'status');
    }


    protected function containsSecondaryKeywords($text)
    {
        foreach ($this->getSecondaryKeywords() as $keyword) {
            if (str_contains(strtolower($text), strtolower($keyword))) {
                return true;
            }
        }
        return false;
    }

    protected function analyzeContentLength()
    {
        $wordCount = str_word_count(strip_tags($this->content));
        $score = 0;
        $message = '';
        $status = 'error';

        if ($wordCount > 2500) {
            $score = 100;
            $message = sprintf("Tuyệt vời! Độ dài nội dung (%d từ) - Nội dung rất phong phú", $wordCount);
            $status = 'success';
        } elseif ($wordCount >= 2000) {
            $score = 70;
            $message = sprintf("Độ dài nội dung tốt (%d từ) - Nội dung phong phú, nhưng có thể phát triển thêm", $wordCount);
            $status = 'success';
        } elseif ($wordCount >= 1500) {
            $score = 60;
            $message = sprintf("Độ dài nội dung khá tốt (%d từ) - Có thể cải thiện thêm để đạt điểm cao hơn", $wordCount);
            $status = 'success';
        } elseif ($wordCount >= 1000) {
            $score = 40;
            $message = sprintf("Độ dài nội dung trung bình (%d từ) - Nên phát triển thêm để đạt điểm tốt hơn", $wordCount);
            $status = 'warning';
        } elseif ($wordCount >= 600) {
            $score = 20;
            $message = sprintf("Nội dung quá ngắn (%d từ) - Cần phát triển thêm để đạt ít nhất 1000 từ", $wordCount);
            $status = 'warning';
        } elseif ($wordCount > 0) {
            $score = 0;
            $message = sprintf("Nội dung quá ngắn (%d từ) - Cần phát triển thêm để đạt ít nhất 600 từ", $wordCount);
            $status = 'error';
        } else {
            $score = 0;
            $message = "Nội dung không được để trống, nên dài từ 600-2500 từ để đạt hiệu quả cao.";
            $status = 'error';
        }

        $this->analyses['contentLength'] = [
            'score' => $score,
            'message' => $message,
            'status' => $status,
        ];
    }


    protected function analyzeKeywordHistory($currentRecordId = null)
    {
        if (empty($this->focusKeyword)) {
            $this->advancedAnalyses['keywordHistory'] = [
                'score' => 0,
                'message' => 'Thiết lập từ khóa cho nội dung này.',
                'status' => 'error'
            ];
            return;
        }

        $primaryKeyword = $this->getPrimaryKeyword();
        $existingPrimary = Post::where('id', '!=', $currentRecordId)
            ->where('seo_keywords', 'like', '%' . $primaryKeyword . '%')
            ->exists();

        if ($existingPrimary) {
            $score = 0;
            $message = 'Từ khóa chính đã được sử dụng trong các bài viết khác.';
            $status = 'warning';
        } else {
            $score = 100;
            $message = 'Tất cả từ khóa đều chưa được sử dụng trước đó.';
            $status = 'success';
        }

        $this->advancedAnalyses['keywordHistory'] = [
            'score' => $score,
            'message' => $message,
            'status' => $status
        ];
    }

    protected function analyzeReadability()
    {
        $this->analyzeParagraphLength();
        $this->analyzeMediaContent();

        $totalScore = 0;
        $count = 0;

        foreach ($this->readabilityAnalyses as $analysis) {
            if ($analysis['status'] !== 'pending') {
                $totalScore += $analysis['score'];
                $count++;
            }
        }

        $averageScore = $count > 0 ? $totalScore / $count : 0;

        // Cập nhật trạng thái chung của readability
        foreach ($this->readabilityAnalyses as &$analysis) {
            if ($analysis['status'] === 'pending') {
                $analysis['status'] = 'error';
                $analysis['message'] = 'Chưa được phân tích';
                $analysis['score'] = 0;
            }
        }

        return $averageScore;
    }

    protected function analyzeAdvancedSEO($currentRecordId = null)
    {
        $this->analyzeSubheadings();
        $this->analyzeImages();
        $this->analyzeKeywordDensity();
        $this->analyzeUrlLength();
        $this->analyzeExternalLinks();
        $this->analyzeInternalLinks();
        $this->analyzeKeywordHistory($currentRecordId);

        // Tính điểm trung bình cho advanced SEO
        $totalScore = 0;
        $count = 0;

        foreach ($this->advancedAnalyses as $analysis) {
            if ($analysis['status'] !== 'pending') {
                $totalScore += $analysis['score'];
                $count++;
            }
        }

        return $count > 0 ? $totalScore / $count : 0;
    }

    //note
    protected function analyzeUrlLength()
    {
        $baseUrl = config('app.url');
        $fullUrl = rtrim($baseUrl, '/') . '/' . ltrim($this->slug, '/');

        if (empty($this->slug)) {
            $this->advancedAnalyses['urlLength'] = [
                'score' => 0,
                'message' => 'URL không khả dụng, hãy thêm một URL ngắn.',
                'status' => 'error',
            ];
            return;
        }

        $length = Str::length($fullUrl);
        $wordCount = str_word_count($this->slug);

        if ($length > 75) {
            $status = 'error';
            $message = "URL quá dài ($length ký tự)";
            $score = 33;
        } elseif ($length > 65) {
            $status = 'warning';
            $message = "URL hơi dài ($length ký tự)";
            $score = 66;
        } elseif ($length >= 50) {
            $status = 'success';
            $message = "Độ dài URL tốt ($length ký tự)";
            $score = 100;
        } elseif ($length > 30) {
            $status = 'success';
            $message = "Tuyệt vời! Độ dài URL ($length ký tự)";
            $score = 100;
        } else {
            $status = 'success';
            $message = "Độ dài URL phù hợp ($length ký tự)";
            $score = 100;
        }

        $this->advancedAnalyses['urlLength'] = [
            'score' => $score,
            'message' => $message,
            'status' => $status,
            'details' => [
                'length' => $length,
                'wordCount' => $wordCount,
                'url' => $fullUrl,
            ],
        ];
    }



    protected function analyzeSecondaryKeywords(): void
    {
        $this->secondaryKeywordAnalyses = [];
        $secondaryKeywords = $this->getSecondaryKeywords();

        if (empty($secondaryKeywords)) {
            return;
        }

        $plainContent = strip_tags($this->content ?? '');
        $wordCount = str_word_count($plainContent);

        foreach ($secondaryKeywords as $keyword) {
            $keyword = trim($keyword);
            if ($keyword === '') {
                continue;
            }

            $kwLower = mb_strtolower($keyword, 'UTF-8');
            $titleLower = mb_strtolower($this->title ?? '', 'UTF-8');
            $descLower = mb_strtolower($this->description ?? '', 'UTF-8');
            $contentLower = mb_strtolower($plainContent, 'UTF-8');
            $slugKw = $this->slugify($keyword);

            $inTitle = $titleLower !== '' && str_contains($titleLower, $kwLower);
            $inDescription = $descLower !== '' && str_contains($descLower, $kwLower);
            $inContent = $contentLower !== '' && str_contains($contentLower, $kwLower);
            $inUrl = $this->slug !== '' && str_contains($this->slug, $slugKw);

            $count = $wordCount > 0 ? substr_count($contentLower, $kwLower) : 0;
            $density = $wordCount > 0 ? round(($count / $wordCount) * 100, 2) : 0.0;

            if ($inContent && ($inTitle || $inDescription)) {
                $status = 'success';
                $parts = array_filter([
                    $inTitle ? 'tiêu đề' : null,
                    $inDescription ? 'mô tả' : null,
                    'nội dung',
                ]);
                $message = 'Xuất hiện trong ' . implode(', ', $parts);
                $score = 100;
            } elseif ($inContent) {
                $status = 'warning';
                $message = 'Có trong nội dung nhưng thiếu trong tiêu đề và mô tả';
                $score = 50;
            } else {
                $status = 'error';
                $message = 'Chưa xuất hiện trong nội dung';
                $score = 0;
            }

            $this->secondaryKeywordAnalyses[$keyword] = [
                'keyword'       => $keyword,
                'score'         => $score,
                'status'        => $status,
                'message'       => $message,
                'inTitle'       => $inTitle,
                'inDescription' => $inDescription,
                'inContent'     => $inContent,
                'inUrl'         => $inUrl,
                'density'       => $density,
                'count'         => $count,
            ];
        }
    }

    public function render()
    {
        return view('post::livewire.s-e-o', [
            'score'                    => $this->score,
            'analyses'                 => $this->analyses,
            'advancedAnalyses'         => $this->advancedAnalyses,
            'readabilityAnalyses'      => $this->readabilityAnalyses,
            'secondaryKeywordAnalyses' => $this->secondaryKeywordAnalyses,
            'style'                    => $this->style,
        ]);
    }
}
