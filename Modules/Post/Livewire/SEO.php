<?php

namespace Modules\Post\Livewire;

use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;
use Livewire\Component;
use Illuminate\Support\Str;
use Modules\Post\Entities\Post;

// Chấm điểm SEO bài viết ở admin. Tiêu chí bám theo tài liệu hiện hành của Google Search Central, không theo
// các "luật" kiểu plugin cũ:
// - Nội dung hữu ích, viết cho người đọc (helpful content, đã nhập vào core ranking 2024): KHÔNG chấm theo số từ hay
//   mật độ từ khóa %, mà phạt nhồi từ khóa (spam policies: keyword stuffing) và nội dung mỏng/trùng lặp.
// - Title link & snippet: Google cắt theo độ rộng hiển thị (~55–60 ký tự / ~150–160 ký tự), không có giới hạn cứng.
// - Liên kết: link nội bộ có anchor mô tả giúp Google khám phá và hiểu trang; link ra ngoài không phải yếu tố xếp hạng.
// - Ảnh: alt mô tả đúng nội dung ảnh (không nhồi từ khóa), khai báo width/height để tránh nhảy layout (CLS).
// - Cannibalization: 2 bài cùng nhắm 1 từ khóa chính thì Google phải chọn 1, cả hai đều khó lên.
// Trang chi tiết bài viết tự render tiêu đề bài làm H1 và tự thêm " | 365Home" vào thẻ <title> khi seo_title trống hoặc
// trùng tiêu đề (BladeThemeV1Controller::postDetail()) — phần chấm điểm mô phỏng đúng hành vi đó.
class SEO extends Component
{
    // Domain chính thức (xem App\Http\Middleware\RedirectToCanonicalDomain) — link tuyệt đối tới domain này vẫn là link nội bộ.
    private const CANONICAL_HOST = '365home.vn';

    private const LABELS = [
        'title'            => 'Tiêu đề SEO (thẻ title)',
        'description'      => 'Mô tả meta',
        'url'              => 'Đường dẫn (URL)',
        'keywordPlacement' => 'Đoạn mở đầu',
        'contentLength'    => 'Độ sâu nội dung',
        'subheadings'      => 'Cấu trúc H2/H3',
        'images'           => 'Hình ảnh & alt',
        'keywordDensity'   => 'Dùng từ khóa tự nhiên',
        'internalLinks'    => 'Liên kết nội bộ',
        'externalLinks'    => 'Nguồn tham khảo bên ngoài',
        'keywordHistory'   => 'Trùng từ khóa với bài khác',
        'duplicateContent' => 'Trùng nội dung với bài khác',
        'technical'        => 'Lỗi kỹ thuật trong nội dung',
        'freshness'        => 'Độ mới',
        'paragraphLength'  => 'Độ dài đoạn văn',
        'mediaContent'     => 'Hình ảnh, video minh họa',
    ];

    public $content = '';
    public $title = '';
    public $seoTitle = '';
    public $description = '';
    public $focusKeyword = [];
    public $slug = '';
    public $score = 0;
    public $scoreNote = '';
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
        $this->scoreNote = '';
    }

    protected function getDefaultAnalyses()
    {
        return $this->defaultsFor(['title', 'description', 'url', 'keywordPlacement', 'contentLength']);
    }

    protected function getDefaultAdvancedAnalyses()
    {
        return $this->defaultsFor([
            'subheadings', 'images', 'keywordDensity', 'internalLinks', 'externalLinks',
            'keywordHistory', 'duplicateContent', 'technical', 'freshness',
        ]);
    }

    protected function getDefaultReadabilityAnalyses()
    {
        return $this->defaultsFor(['paragraphLength', 'mediaContent']);
    }

    protected function defaultsFor(array $keys): array
    {
        return collect($keys)->mapWithKeys(fn ($key) => [$key => $this->defaultAnalysis($key)])->all();
    }

    protected function defaultAnalysis($key)
    {
        return [
            'label' => self::LABELS[$key] ?? $key,
            'score' => 0,
            'message' => 'Chưa phân tích',
            'status' => 'pending',
        ];
    }

    // Ghi kết quả 1 tiêu chí. Không truyền $status thì suy ra từ điểm.
    protected function setResult(string $group, string $key, int|float $score, string $message, ?string $status = null): void
    {
        $score = (int) max(0, min(100, round($score)));
        $status ??= $score >= 80 ? 'success' : ($score >= 50 ? 'warning' : 'error');

        $this->{$group}[$key] = [
            'label' => self::LABELS[$key] ?? $key,
            'score' => $score,
            'message' => $message,
            'status' => $status,
        ];
    }

    protected function loadRecordData($record)
    {
        $this->content = $record['content'] ?? $this->content;
        $this->title = $record['title'] ?? $this->title;
        $this->seoTitle = $record['seo_title'] ?? $this->seoTitle;
        $this->description = $record['seo_description'] ?? $this->description;
        $this->slug = $record['slug'] ?? $this->slug;
        $this->focusKeyword = $this->extractFocusKeywords($record['seo_keywords'] ?? '');
    }

    protected function extractFocusKeywords($keywords)
    {
        if (is_array($keywords)) {
            return array_values(array_filter(array_map('trim', $keywords)));
        }

        $decoded = json_decode((string) $keywords, true);
        if (is_array($decoded)) {
            return array_values(array_filter(array_map('trim', $decoded)));
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $keywords))));
    }

    #[On('seon')]
    public function updateContent($data)
    {
        $this->initializeAnalyses();
        $this->content = $data['content'] ?? $this->content;
        $this->title = $data['title'] ?? $this->title;
        $this->seoTitle = $data['seo_title'] ?? $this->seoTitle;
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

        $score = $totalWeight > 0 ? round($totalScore / $totalWeight) : 0;

        // Tín hiệu spam/chất lượng thấp mà Google xử lý nặng tay: dù các mục khác tốt đến đâu, bài vẫn khó lên top
        // → giới hạn điểm tối đa để người viết không bị điểm cao đánh lừa.
        $primary = $this->getPrimaryKeyword();
        $blockers = array_filter([
            $primary && $this->advancedAnalyses['keywordDensity']['score'] === 0 && $this->countKeyword($primary) > 0
                ? 'nhồi từ khóa' : null,
            $primary && $this->advancedAnalyses['keywordHistory']['status'] === 'error' ? 'trùng từ khóa chính với bài khác' : null,
            $this->advancedAnalyses['duplicateContent']['status'] === 'error' ? 'trùng nội dung với bài khác' : null,
            $this->analyses['contentLength']['status'] === 'error' ? 'nội dung quá mỏng' : null,
        ]);

        if ($blockers && $score > 60) {
            $score = 60;
            $this->scoreNote = 'Điểm bị giới hạn ở 60 vì: '.implode(', ', $blockers).'. Sửa các lỗi này trước.';
        }

        return $score;
    }

    protected function getAnalysisWeights()
    {
        return [
            // Cơ bản
            'analyses' => [
                'title' => 14,
                'description' => 7,
                'url' => 4,
                'keywordPlacement' => 7,
                'contentLength' => 6,
            ],
            // Bổ sung
            'advancedAnalyses' => [
                'subheadings' => 7,
                'images' => 5,
                'keywordDensity' => 12,
                'internalLinks' => 8,
                'externalLinks' => 2,
                'keywordHistory' => 10,
                'duplicateContent' => 5,
                'technical' => 6,
                'freshness' => 1,
            ],
            // Nội dung
            'readabilityAnalyses' => [
                'paragraphLength' => 4,
                'mediaContent' => 2,
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
        return array_values(array_filter(array_map('trim', array_slice($this->focusKeyword, 1))));
    }

    protected function containsKeyword(string $field): bool
    {
        $primaryKeyword = $this->getPrimaryKeyword();
        if (empty($primaryKeyword) || empty($field)) {
            return false;
        }

        return $this->fieldContainsKeyword($field, $primaryKeyword);
    }

    protected function containsAnyKeyword(string $field): bool
    {
        foreach ($this->focusKeyword as $keyword) {
            if ($this->fieldContainsKeyword($field, $keyword)) {
                return true;
            }
        }

        return false;
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
            $str = preg_replace('/\s+/u', ' ', $str);
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

    // Văn bản thuần của một đoạn HTML: bỏ comment và thẻ (thay bằng khoảng trắng để chữ 2 bên thẻ không dính vào
    // nhau), decode entity — trước đây cleanText($this->content) giữ nguyên thẻ nên đếm cả "<p>", "href=..." là từ.
    protected function plainText(string $html): string
    {
        $html = preg_replace(['/<!--.*?-->/s', '/<(script|style)\b.*?<\/\1>/is'], ' ', $html);
        $text = preg_replace('/<[^>]+>/', ' ', $html);

        return $this->cleanText($text, ['normalize_unicode' => true]);
    }

    // Chuẩn hoá để so khớp từ khóa: chữ thường, bỏ dấu, bỏ dấu câu, gộp khoảng trắng — "Cần Thơ", "can tho", "CẦN  THƠ"
    // như nhau; "check-in" khớp "check in", "“Ở Nhà”" khớp "ở nhà".
    protected function matchText(string $text): string
    {
        $text = $this->removeVietnameseTones(mb_strtolower($this->cleanText($text, ['normalize_unicode' => true]), 'UTF-8'));

        return trim((string) preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text)));
    }

    // str_word_count() chỉ nhận a-z/A-Z (+'/-), đếm sai hoàn toàn với tiếng Việt có dấu — dùng
    // regex Unicode \S+ thay thế (đã áp dụng đúng trong analyzeSecondaryKeywords(), giờ dùng
    // chung cho mọi chỗ đếm từ khác để nhất quán).
    protected function countWords(string $text): int
    {
        preg_match_all('/\S+/u', trim($text), $matches);
        return count($matches[0]);
    }

    // Số lần cụm từ khóa xuất hiện trong nội dung (không phân biệt hoa thường/dấu, chỉ khớp nguyên từ).
    protected function countKeyword(string $keyword, ?string $text = null): int
    {
        $needle = $this->matchText($keyword);
        if ($needle === '') {
            return 0;
        }

        return preg_match_all('/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u', $this->matchText($text ?? $this->plainText((string) $this->content)));
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

    // Thẻ <title> thật sự render ở trang bài viết — cùng quy tắc với BladeThemeV1Controller::postDetail()/brandedSeoTitle():
    // seo_title trống hoặc trùng tiêu đề bài thì hệ thống tự thêm " | 365Home".
    protected function effectiveTitleTag(): string
    {
        $title = trim((string) $this->title);
        $seoTitle = trim((string) $this->seoTitle);

        if ($seoTitle !== '' && $seoTitle !== $title) {
            return $seoTitle;
        }
        if ($title === '') {
            return '';
        }

        $core = (string) preg_replace('/^365\s*home\s*[|:–—-]\s*/iu', '', $title);
        $core = trim((string) preg_replace('/\s*[|–—-]\s*365\s*home\s*$/iu', '', $core));
        $core = $core !== '' ? $core : $title;

        return preg_match('/(?<![\p{L}\p{N}])365\s*home(?![\p{L}\p{N}])/iu', $core) ? $core : $core.' | 365Home';
    }

    protected function isBrandKeyword(string $keyword): bool
    {
        return str_replace(' ', '', $this->matchText($keyword)) === '365home';
    }

    protected function isAllCaps(string $text): bool
    {
        $letters = preg_replace('/[^\p{L}]/u', '', $text);

        return mb_strlen($letters) >= 10 && $letters === mb_strtoupper($letters, 'UTF-8');
    }

    protected function analyzeTitle()
    {
        $titleTag = $this->effectiveTitleTag();

        if ($titleTag === '') {
            $this->setResult('analyses', 'title', 0, 'Chưa có tiêu đề. Viết tiêu đề chứa từ khóa chính, mô tả đúng nội dung bài.');
            return;
        }

        $primary = $this->getPrimaryKeyword();
        $length = Str::length($titleTag);
        $issues = [];
        $tips = [];

        if ($primary && $this->containsKeyword($titleTag)) {
            $score = 100;
            $position = mb_strpos($this->matchText($titleTag), $this->matchText($primary));
            if ($position !== false && $position > $length * 0.5) {
                $score -= 10;
                $tips[] = 'đưa từ khóa chính lên nửa đầu tiêu đề';
            }
            if ($this->countKeyword($primary, $titleTag) > 1) {
                $score = 40;
                $issues[] = 'từ khóa chính lặp lại trong tiêu đề (bị coi là nhồi từ khóa)';
            }
        } elseif ($primary && collect($this->getSecondaryKeywords())->contains(fn ($k) => $this->fieldContainsKeyword($titleTag, $k))) {
            $score = 50;
            $issues[] = 'tiêu đề chỉ có từ khóa phụ, thiếu từ khóa chính';
        } else {
            $score = $primary ? 10 : 50;
            $issues[] = $primary ? 'tiêu đề chưa có từ khóa chính' : 'chưa thiết lập từ khóa chính';
        }

        // Google cắt title theo độ rộng (~600px ≈ 55–60 ký tự tiếng Việt); quá ngắn thì Google hay tự viết lại.
        if ($length > 65) {
            $score -= 30;
            $issues[] = "dài $length ký tự, Google sẽ cắt bớt (nên ≤ 60, đã tính cả \" | 365Home\")";
        } elseif ($length > 60) {
            $score -= 10;
            $tips[] = "dài $length ký tự, có thể bị cắt trên điện thoại";
        } elseif ($length < 30) {
            $score -= 20;
            $tips[] = "hơi ngắn ($length ký tự), Google có thể tự viết lại tiêu đề";
        }

        if ($this->isAllCaps((string) $this->title) || $this->isAllCaps($titleTag)) {
            $score -= 15;
            $issues[] = 'viết hoa toàn bộ — khó đọc và trông như spam, nên viết hoa chữ cái đầu';
        }

        $this->setResult('analyses', 'title', $score, $this->composeMessage(
            'Tiêu đề tốt: có từ khóa chính, độ dài hiển thị đủ trên Google',
            $issues,
            $tips
        )." Thẻ title thực tế: \"$titleTag\"");
    }

    protected function analyzeDescription()
    {
        $description = trim((string) $this->description);

        if ($description === '') {
            $this->setResult('analyses', 'description', 0, 'Chưa có mô tả meta. Google sẽ tự cắt một đoạn trong bài, thường kém hấp dẫn — viết 1–2 câu nêu lợi ích chính, có từ khóa.');
            return;
        }

        $length = Str::length($description);
        $issues = [];

        // Google cắt snippet khoảng 150–160 ký tự trên máy tính, ~120 trên điện thoại.
        if ($length < 70) {
            $lengthScore = 40;
            $issues[] = "quá ngắn ($length ký tự), nên 120–160";
        } elseif ($length < 120) {
            $lengthScore = 75;
            $issues[] = "hơi ngắn ($length ký tự), nên 120–160";
        } elseif ($length > 160) {
            $lengthScore = 70;
            $issues[] = "dài $length ký tự, phần sau 160 sẽ bị cắt";
        } else {
            $lengthScore = 100;
        }

        // Mô tả không phải yếu tố xếp hạng, nhưng từ khóa được in đậm trong kết quả tìm kiếm → tăng tỷ lệ bấm (CTR).
        if ($this->containsKeyword($description)) {
            $keywordScore = 100;
        } elseif ($this->containsAnyKeyword($description)) {
            $keywordScore = 60;
            $issues[] = 'thiếu từ khóa chính (Google in đậm từ khóa trong kết quả, giúp tăng lượt bấm)';
        } else {
            $keywordScore = 20;
            $issues[] = 'chưa có từ khóa nào';
        }

        if (mb_strtolower($description) === mb_strtolower(trim((string) $this->title))) {
            $keywordScore = min($keywordScore, 50);
            $issues[] = 'trùng y hệt tiêu đề — nên viết câu tóm tắt lợi ích riêng';
        }

        $this->setResult('analyses', 'description', ($lengthScore + $keywordScore) / 2, $this->composeMessage(
            "Mô tả tốt: $length ký tự, có từ khóa chính",
            $issues
        ));
    }

    protected function analyzeURL()
    {
        $slug = trim((string) $this->slug);

        if ($slug === '') {
            $this->setResult('analyses', 'url', 0, 'Chưa có đường dẫn. Dùng vài từ ngắn gọn, không dấu, mô tả chủ đề.');
            return;
        }

        $issues = [];
        $tips = [];

        // Google: từ khóa trong URL chỉ là tín hiệu rất nhỏ — URL dễ đọc, ngắn, ổn định mới là chính.
        if ($this->containsKeyword($slug)) {
            $score = 100;
        } elseif (collect($this->getSecondaryKeywords())->contains(fn ($k) => $this->slugContainsKeyword($slug, $k))) {
            $score = 75;
            $tips[] = 'nên chứa từ khóa chính';
        } else {
            $score = 50;
            $issues[] = 'chưa chứa từ khóa';
        }

        $words = count(array_filter(explode('-', $slug)));
        if ($words > 8) {
            $score -= 20;
            $issues[] = "dài $words từ, nên gọn 3–6 từ";
        }

        if (preg_match('/(?:^|-)20\d{2}(?:-|$)/', $slug)) {
            $score -= 15;
            $issues[] = 'có năm trong URL — sang năm phải đổi URL và mất thứ hạng, nên bỏ năm (để năm trong tiêu đề)';
        }

        $this->setResult('analyses', 'url', $score, $this->composeMessage('URL ngắn gọn, có từ khóa chính', $issues, $tips));
    }

    protected function analyzeKeywordPlacement()
    {
        $primary = $this->getPrimaryKeyword();
        $plain = $this->plainText((string) $this->content);

        if ($plain === '' || ! $primary) {
            $this->setResult('analyses', 'keywordPlacement', 0, 'Cần có nội dung và từ khóa chính để kiểm tra đoạn mở đầu.');
            return;
        }

        // ~120 từ đầu ≈ đoạn mở bài. Nêu rõ chủ đề ngay đầu giúp người đọc biết đã vào đúng trang và Google hiểu bài nhanh.
        $intro = implode(' ', array_slice(preg_split('/\s+/u', $plain), 0, 120));

        if ($this->containsKeyword($intro)) {
            $this->setResult('analyses', 'keywordPlacement', 100, 'Đoạn mở đầu đã nêu rõ chủ đề (có từ khóa chính).');
        } elseif ($this->containsAnyKeyword($intro)) {
            $this->setResult('analyses', 'keywordPlacement', 60, 'Đoạn mở đầu mới có từ khóa phụ — nên nêu từ khóa chính trong khoảng 100 từ đầu.');
        } else {
            $this->setResult('analyses', 'keywordPlacement', 0, 'Từ khóa chính chưa có trong đoạn mở đầu. Hãy trả lời ngay câu hỏi người đọc đang tìm trong 2–3 câu đầu.');
        }
    }

    protected function analyzeKeywordDensity()
    {
        $primary = $this->getPrimaryKeyword();
        $plain = $this->plainText((string) $this->content);

        if ($plain === '' || ! $primary) {
            $this->setResult('advancedAnalyses', 'keywordDensity', 0, 'Cần có nội dung và từ khóa chính để kiểm tra.');
            return;
        }

        // Tên thương hiệu được nhắc nhiều là bình thường, không đo nhồi từ khóa trên nó.
        if ($this->isBrandKeyword($primary)) {
            $this->setResult('advancedAnalyses', 'keywordDensity', 50, 'Từ khóa chính đang là tên thương hiệu — đặt từ khóa chủ đề làm từ khóa chính để kiểm tra cách dùng từ khóa.', 'warning');
            return;
        }

        $words = $this->countWords($plain);
        $count = $this->countKeyword($primary, $plain);

        // Không dùng "mật độ %" (Google không có con số tối ưu). Đo khoảng cách trung bình giữa 2 lần nhắc:
        // dưới ~60 từ/lần là đọc thấy lặp — đúng kiểu "keyword stuffing" trong chính sách spam của Google.
        if ($count === 0) {
            $this->setResult('advancedAnalyses', 'keywordDensity', 0, 'Từ khóa chính chưa xuất hiện trong nội dung.');
            return;
        }

        $gap = (int) round($words / $count);
        // Trang ngắn (hướng dẫn, quy định) nhắc chủ đề vài lần là tự nhiên — chỉ coi là nhồi khi dày hơn hẳn.
        $stuffingGap = $words < 400 ? 40 : 60;

        if ($gap < $stuffingGap) {
            $this->setResult('advancedAnalyses', 'keywordDensity', 0, "Nhồi từ khóa: \"$primary\" lặp $count lần (~1 lần mỗi $gap từ). Google coi là spam — giữ khoảng ".max(2, (int) floor($words / 150)).' lần, còn lại dùng đại từ, biến thể hoặc từ đồng nghĩa.');
        } elseif ($gap < 100) {
            $this->setResult('advancedAnalyses', 'keywordDensity', 60, "Từ khóa chính hơi dày: $count lần (~1 lần mỗi $gap từ). Nên thay bớt bằng biến thể tự nhiên.");
        } elseif ($count === 1 && $words > 800) {
            $this->setResult('advancedAnalyses', 'keywordDensity', 65, "Từ khóa chính chỉ xuất hiện 1 lần trong $words từ — nhắc lại tự nhiên ở thân bài và kết luận.");
        } else {
            $this->setResult('advancedAnalyses', 'keywordDensity', 100, "Dùng từ khóa tự nhiên: $count lần trong $words từ (~1 lần mỗi $gap từ).");
        }
    }

    protected function analyzeSubheadings()
    {
        $content = (string) $this->content;
        $words = $this->countWords($this->plainText($content));

        preg_match_all('/<h([2-6])[^>]*>(.*?)<\/h\1>/is', $content, $matches, PREG_SET_ORDER);
        $headings = array_map(fn ($m) => ['level' => (int) $m[1], 'text' => $this->plainText($m[2])], $matches);

        if (empty($headings)) {
            $this->setResult('advancedAnalyses', 'subheadings', $words > 300 ? 0 : 60, $words > 300
                ? 'Chưa có tiêu đề phụ. Chia bài thành các mục H2/H3 theo từng câu hỏi người đọc quan tâm.'
                : 'Bài ngắn nên chưa cần nhiều tiêu đề phụ, nhưng nên có ít nhất 1–2 mục H2.');
            return;
        }

        $score = 100;
        $issues = [];
        $tips = [];

        if (! collect($headings)->contains(fn ($h) => $h['level'] === 2)) {
            $score -= 30;
            $issues[] = 'chưa có H2 — mục lớn nên dùng H2, mục con dùng H3';
        }

        $previous = 1; // H1 là tiêu đề bài (template tự render)
        foreach ($headings as $heading) {
            if ($heading['level'] > $previous + 1) {
                $score -= 15;
                $issues[] = "nhảy cấp tiêu đề (H{$previous} → H{$heading['level']})";
                break;
            }
            $previous = $heading['level'];
        }

        $sections = count(array_filter($headings, fn ($h) => $h['level'] <= 3));
        if ($words > 600 && $words / max(1, $sections) > 400) {
            $score -= 15;
            $tips[] = 'có mục quá dài, nên chia nhỏ (~200–350 từ mỗi mục)';
        }

        $withPrimary = count(array_filter($headings, fn ($h) => $this->containsKeyword($h['text'])));
        $withAny = count(array_filter($headings, fn ($h) => $this->containsAnyKeyword($h['text'])));

        if ($this->getPrimaryKeyword() && $withAny === 0) {
            $score -= 20;
            $tips[] = 'nên có từ khóa chính hoặc biến thể trong ít nhất 1 tiêu đề phụ';
        }
        // Từ khóa chính lặp ở hơn nửa số tiêu đề phụ = tối ưu quá đà, đọc như viết cho máy.
        if (count($headings) >= 4 && $withPrimary / count($headings) > 0.5) {
            $score -= 30;
            $issues[] = "từ khóa chính lặp ở $withPrimary/".count($headings).' tiêu đề phụ — tối ưu quá đà, nên đặt tiêu đề theo nội dung từng mục';
        }

        $this->setResult('advancedAnalyses', 'subheadings', $score, $this->composeMessage(
            'Cấu trúc tốt: '.count($headings).' tiêu đề phụ, phân cấp hợp lý',
            $issues,
            $tips
        ));
    }

    protected function analyzeImages()
    {
        preg_match_all('/<img\b[^>]*>/i', (string) $this->content, $matches);
        $images = $matches[0] ?? [];

        // Ảnh đại diện bắt buộc và tự lấy alt theo tiêu đề bài (post-detail.blade.php) nên không cần chấm ở đây.
        if (empty($images)) {
            $this->setResult('advancedAnalyses', 'images', 60, 'Chưa có ảnh trong nội dung (chỉ có ảnh đại diện). Thêm ảnh thật của phòng/địa điểm để minh họa và có thêm cơ hội lên Google Hình ảnh.');
            return;
        }

        $total = count($images);
        $missingAlt = 0;
        $tooLong = 0;
        $noSize = 0;
        $withPrimary = 0;
        $withAny = 0;
        $alts = [];

        foreach ($images as $img) {
            preg_match('/\balt\s*=\s*(["\'])(.*?)\1/is', $img, $alt);
            $altText = trim(html_entity_decode($alt[2] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            // alt trống hoặc chỉ là tên file (abc.jpg, chuỗi ký tự ngẫu nhiên) coi như thiếu.
            if ($altText === '' || preg_match('/\.(jpe?g|png|webp|gif|avif)$/i', $altText) || preg_match('/^[A-Za-z0-9_-]{20,}$/', $altText)) {
                $missingAlt++;
                continue;
            }

            $alts[] = mb_strtolower($altText);
            if (mb_strlen($altText) > 125) {
                $tooLong++;
            }
            if ($this->containsKeyword($altText)) {
                $withPrimary++;
            }
            if ($this->containsAnyKeyword($altText)) {
                $withAny++;
            }
            if (! preg_match('/\bwidth\s*=/i', $img) || ! preg_match('/\bheight\s*=/i', $img)) {
                $noSize++;
            }
        }

        $score = 100;
        $issues = [];
        $tips = [];

        if ($missingAlt > 0) {
            $score -= 60 * $missingAlt / $total;
            $issues[] = "$missingAlt/$total ảnh thiếu alt — mô tả ngắn gọn ảnh đang thể hiện gì";
        }
        if ($tooLong > 0) {
            $score -= 10;
            $tips[] = "$tooLong ảnh có alt quá dài (>125 ký tự)";
        }
        if (count($alts) - count(array_unique($alts)) > 0) {
            $score -= 10;
            $tips[] = 'nhiều ảnh dùng chung một alt — mỗi ảnh nên mô tả riêng';
        }
        if (count($alts) >= 3 && $withPrimary / count($alts) > 0.6) {
            $score -= 25;
            $issues[] = "từ khóa chính có trong $withPrimary/".count($alts).' alt — alt để mô tả ảnh, nhồi từ khóa vào alt bị coi là spam';
        } elseif ($this->getPrimaryKeyword() && $withAny === 0 && count($alts) > 0) {
            $score -= 10;
            $tips[] = 'có thể dùng từ khóa ở 1 ảnh nếu đúng với nội dung ảnh';
        }
        if ($noSize > 0) {
            $score -= 10;
            $tips[] = "$noSize ảnh chưa khai báo width/height (làm trang bị nhảy khi tải — ảnh hưởng Core Web Vitals)";
        }

        $this->setResult('advancedAnalyses', 'images', $score, $this->composeMessage("Ảnh tốt: $total ảnh đều có alt mô tả", $issues, $tips));
    }

    // Tách các link trong nội dung: [['href' => ..., 'text' => ..., 'tag' => thẻ <a> gốc], ...]
    protected function extractLinks(): array
    {
        preg_match_all('/(<a\b[^>]*>)(.*?)<\/a>/is', (string) $this->content, $matches, PREG_SET_ORDER);

        return array_map(function ($m) {
            preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/is', $m[1], $href);

            return [
                'href' => isset($href[2]) ? trim(html_entity_decode($href[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : null,
                'text' => $this->plainText($m[2]),
                'tag'  => $m[1],
            ];
        }, $matches);
    }

    protected function isInternalUrl(string $url): bool
    {
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return true;
        }

        $host = preg_replace('/^www\./', '', (string) parse_url($url, PHP_URL_HOST));
        $hosts = array_filter([preg_replace('/^www\./', '', (string) parse_url(config('app.url'), PHP_URL_HOST)), self::CANONICAL_HOST]);

        return $host !== '' && in_array($host, $hosts, true);
    }

    protected function analyzeExternalLinks()
    {
        $external = array_filter($this->extractLinks(), fn ($l) => $l['href'] && preg_match('#^https?://#i', $l['href']) && ! $this->isInternalUrl($l['href']));

        // Link ra ngoài không phải yếu tố xếp hạng — chỉ khuyến khích dẫn nguồn khi bài có số liệu/thông tin cần kiểm chứng.
        if (empty($external)) {
            $this->setResult('advancedAnalyses', 'externalLinks', 80, 'Không bắt buộc. Khi đưa số liệu, quy định, thông tin du lịch… nên dẫn nguồn uy tín để tăng độ tin cậy.', 'success');
            return;
        }

        $this->setResult('advancedAnalyses', 'externalLinks', 100, 'Có '.count($external).' liên kết dẫn nguồn bên ngoài.');
    }

    protected function analyzeInternalLinks()
    {
        $links = array_filter($this->extractLinks(), fn ($l) => $l['href'] && $this->isInternalUrl($l['href']));
        $words = $this->countWords($this->plainText((string) $this->content));

        if (empty($links)) {
            $this->setResult('advancedAnalyses', 'internalLinks', 0, 'Chưa có liên kết nội bộ. Link tới bài viết, trang phòng liên quan giúp Google tìm và hiểu bài, khách cũng dễ đặt phòng hơn.');
            return;
        }

        $path = fn ($url) => rtrim((string) parse_url($url, PHP_URL_PATH), '/');
        $deep = array_unique(array_filter(array_map(fn ($l) => $path($l['href']), $links), fn ($p) => $p !== ''));
        $needed = $words >= 1500 ? 3 : ($words >= 600 ? 2 : 1);

        // Anchor chung chung không cho Google biết trang đích nói về gì.
        $generic = array_filter($links, fn ($l) => preg_match('/^(tại đây|ở đây|xem thêm|xem tại đây|bấm vào đây|nhấn vào đây|click|click here|link|đây|here)$/iu', trim($l['text'])));

        $issues = [];
        $tips = [];

        if (empty($deep)) {
            $score = 40;
            $issues[] = 'chỉ có link về trang chủ — nên link tới bài viết hoặc trang phòng liên quan';
        } elseif (count($deep) < $needed) {
            $score = 70;
            $tips[] = 'mới có '.count($deep)." trang đích, bài dài nên có từ $needed link tới bài/trang phòng liên quan";
        } else {
            $score = 100;
        }

        if ($generic) {
            $score -= 15;
            $tips[] = count($generic).' link dùng chữ chung chung ("tại đây", "xem thêm") — nên dùng cụm mô tả trang đích';
        }

        $this->setResult('advancedAnalyses', 'internalLinks', $score, $this->composeMessage(
            'Liên kết nội bộ tốt: '.count($deep).' trang đích, anchor mô tả rõ',
            $issues,
            $tips
        ));
    }

    protected function analyzeTechnical()
    {
        $content = (string) $this->content;
        $issues = [];

        // Template đã render tiêu đề bài làm H1 — H1 trong nội dung tạo nhiều H1 trên 1 trang.
        if ($h1 = preg_match_all('/<h1\b/i', $content)) {
            $issues[] = "$h1 thẻ H1 trong nội dung (tiêu đề bài đã là H1) — đổi thành H2";
        }
        // Ghi chú ẩn vẫn nằm trong mã nguồn trang, Google và đối thủ đều đọc được.
        if ($comments = preg_match_all('/<!--.*?-->/s', $content)) {
            $issues[] = "$comments ghi chú ẩn <!-- --> còn sót trong nội dung";
        }

        $links = $this->extractLinks();
        $broken = array_filter($links, fn ($l) => $l['href'] === null || $l['href'] === '' || $l['href'] === '#');
        if ($broken) {
            $issues[] = count($broken).' link không có địa chỉ (thiếu href)';
        }
        $tracking = array_filter($links, fn ($l) => $l['href'] && $this->isInternalUrl($l['href']) && preg_match('/[?&](fbclid|gclid|utm_[a-z]+)=/i', $l['href']));
        if ($tracking) {
            $issues[] = count($tracking).' link nội bộ dính tham số theo dõi (fbclid/utm) — tạo URL trùng lặp';
        }
        $insecure = array_filter($links, fn ($l) => $l['href'] && str_starts_with(strtolower($l['href']), 'http://') && $this->isInternalUrl($l['href']));
        if ($insecure) {
            $issues[] = count($insecure).' link nội bộ dùng http:// — đổi sang https:// hoặc đường dẫn tương đối';
        }

        $this->setResult('advancedAnalyses', 'technical', 100 - 30 * count($issues), $issues
            ? 'Cần sửa: '.implode('; ', $issues).'.'
            : 'Không có lỗi kỹ thuật (H1 trùng, ghi chú ẩn, link hỏng, link dính tham số theo dõi).');
    }

    protected function analyzeFreshness()
    {
        $currentYear = (int) now()->year;
        preg_match_all('/\b(20\d{2})\b/', $this->title.' '.$this->effectiveTitleTag(), $matches);
        $years = array_map('intval', array_unique($matches[1]));
        $outdated = array_filter($years, fn ($y) => $y < $currentYear);

        if ($outdated) {
            $this->setResult('advancedAnalyses', 'freshness', 30, 'Tiêu đề ghi năm '.implode(', ', $outdated)." — đã cũ. Cập nhật nội dung (giá, ưu đãi, thông tin) rồi đổi sang $currentYear.");
        } elseif ($years) {
            $this->setResult('advancedAnalyses', 'freshness', 100, "Tiêu đề có năm $currentYear — nhớ cập nhật nội dung và năm khi sang năm mới.");
        } else {
            $this->setResult('advancedAnalyses', 'freshness', 100, 'Tiêu đề không gắn năm. Với bài giá, ưu đãi nên rà lại định kỳ để thông tin luôn đúng.');
        }
    }

    // Từ khóa chính của mỗi bài khác (phần tử đầu tiên của seo_keywords), dạng đã chuẩn hoá.
    protected function otherPostsPrimaryKeywords($currentRecordId): array
    {
        return Post::query()
            ->when($currentRecordId, fn ($q) => $q->where('id', '!=', $currentRecordId))
            ->where('status', '!=', 'archived')
            ->whereNotNull('seo_keywords')
            ->get(['id', 'title', 'seo_keywords'])
            ->map(fn ($post) => [
                'title' => trim((string) $post->title),
                'primary' => $this->matchText((string) ($this->extractFocusKeywords($post->getRawOriginal('seo_keywords'))[0] ?? '')),
            ])
            ->filter(fn ($p) => $p['primary'] !== '')
            ->values()
            ->all();
    }

    protected function analyzeKeywordHistory($currentRecordId = null)
    {
        $primary = $this->getPrimaryKeyword();

        if (! $primary) {
            $this->setResult('advancedAnalyses', 'keywordHistory', 0, 'Thiết lập từ khóa chính cho bài viết (ô từ khóa đầu tiên).');
            return;
        }

        // So khớp NGUYÊN từ khóa chính, không dùng LIKE: "homestay cần thơ" không trùng với bài nhắm "homestay cần thơ giá rẻ".
        $needle = $this->matchText($primary);

        // Tên thương hiệu không phải chủ đề bài: tìm "365Home" thì Google đã đưa trang chủ lên, bài viết nên nhắm chủ đề riêng.
        if ($this->isBrandKeyword($primary)) {
            $this->setResult('advancedAnalyses', 'keywordHistory', 40, 'Từ khóa chính đang là tên thương hiệu "365Home". Hãy chọn đúng chủ đề người đọc sẽ tìm (vd "phòng boxing cần thơ"), đưa "365Home" xuống từ khóa phụ.', 'warning');
            return;
        }

        $conflicts = array_filter($this->otherPostsPrimaryKeywords($currentRecordId), fn ($p) => $p['primary'] === $needle);

        if ($conflicts) {
            $titles = array_map(fn ($p) => '"'.Str::limit($p['title'], 60).'"', array_slice(array_values($conflicts), 0, 2));
            $this->setResult('advancedAnalyses', 'keywordHistory', 0, 'Từ khóa chính đã là từ khóa chính của: '.implode(', ', $titles)
                .'. Hai bài cùng nhắm 1 từ khóa sẽ tranh nhau thứ hạng — đổi sang từ khóa ngách khác, hoặc cập nhật bài cũ thay vì viết bài mới.');
            return;
        }

        $this->setResult('advancedAnalyses', 'keywordHistory', 100, 'Từ khóa chính chưa bài nào khác nhắm tới.');
    }

    // Văn bản các đoạn/ô bảng/mục danh sách đủ dài (≥ 25 từ) của một nội dung, đã chuẩn hoá — dùng để dò trùng lặp.
    protected function paragraphFingerprints(string $html): array
    {
        preg_match_all('/<(p|li|td|blockquote)\b[^>]*>(.*?)<\/\1>/is', $html, $matches);

        $prints = [];
        foreach ($matches[2] as $block) {
            $text = $this->matchText($this->plainText($block));
            if ($this->countWords($text) >= 25) {
                $prints[md5($text)] = true;
            }
        }

        return array_keys($prints);
    }

    protected function analyzeDuplicateContent($currentRecordId = null)
    {
        $mine = $this->paragraphFingerprints((string) $this->content);

        if (empty($mine)) {
            $this->setResult('advancedAnalyses', 'duplicateContent', 100, 'Chưa đủ nội dung để so trùng lặp.', 'success');
            return;
        }

        // Dấu vân tay đoạn văn của mọi bài khác — cache ngắn vì form gọi phân tích lại mỗi lần sửa nội dung.
        $index = Cache::remember('post-seo:paragraph-index', now()->addMinutes(10), function () {
            $index = [];
            Post::query()->where('status', '!=', 'archived')->select(['id', 'title', 'content'])->chunk(50, function ($posts) use (&$index) {
                foreach ($posts as $post) {
                    foreach ($this->paragraphFingerprints((string) $post->content) as $print) {
                        $index[$print][$post->id] = trim((string) $post->title);
                    }
                }
            });

            return $index;
        });

        $duplicated = 0;
        $sources = [];
        foreach ($mine as $print) {
            $others = array_diff_key($index[$print] ?? [], [$currentRecordId => true]);
            // Đoạn có ở ≥ 3 bài khác là phần dùng chung (lời kêu gọi đặt phòng, địa chỉ, liên hệ) — Google coi là
            // boilerplate, không phải nội dung trùng lặp.
            if ($others && count($others) < 3) {
                $duplicated++;
                $sources += $others;
            }
        }

        $ratio = $duplicated / count($mine);

        if ($duplicated < 2 && $ratio < 0.15) {
            $this->setResult('advancedAnalyses', 'duplicateContent', 100, 'Nội dung không trùng với các bài khác (bỏ qua đoạn dùng chung như lời kêu gọi đặt phòng, liên hệ).');
            return;
        }

        $titles = implode(', ', array_map(fn ($t) => '"'.Str::limit($t, 50).'"', array_slice(array_values($sources), 0, 2)));
        $message = "$duplicated/".count($mine)." đoạn trùng y hệt với bài $titles. Viết lại theo góc nhìn riêng của bài này, hoặc tóm tắt ngắn và đặt link sang bài kia.";

        $this->setResult('advancedAnalyses', 'duplicateContent', $ratio >= 0.3 ? 20 : 65, $message);
    }

    protected function analyzeParagraphLength()
    {
        preg_match_all('/<p\b[^>]*>(.*?)<\/p>/is', (string) $this->content, $matches);
        $paragraphs = array_filter(array_map(fn ($p) => $this->plainText($p), $matches[1] ?? []), fn ($p) => $p !== '');

        if (empty($paragraphs)) {
            $this->setResult('readabilityAnalyses', 'paragraphLength', 0, 'Chưa có đoạn văn. Viết thành các đoạn ngắn 2–4 câu để dễ đọc trên điện thoại.');
            return;
        }

        $total = count($paragraphs);
        $long = count(array_filter($paragraphs, fn ($p) => $this->countWords($p) > 150));

        if ($long === 0) {
            $this->setResult('readabilityAnalyses', 'paragraphLength', 100, 'Các đoạn văn ngắn gọn, dễ đọc trên điện thoại.');
            return;
        }

        $this->setResult('readabilityAnalyses', 'paragraphLength', 100 - 200 * $long / $total, "Có $long/$total đoạn rất dài (>150 từ). Nên tách thành đoạn 2–4 câu, dùng gạch đầu dòng khi liệt kê.");
    }

    protected function analyzeMediaContent()
    {
        $content = (string) $this->content;
        $words = $this->countWords($this->plainText($content));

        $images = preg_match_all('/<img\b[^>]*>/i', $content);
        $videos = preg_match_all('/<iframe\b[^>]*>|<video\b[^>]*>/i', $content);
        $total = $images + $videos;
        // Bài dài nên có ảnh/video chia nhịp, khoảng 1 cái mỗi ~600 từ.
        $needed = max(1, (int) ceil($words / 600));

        if ($total === 0) {
            $this->setResult('readabilityAnalyses', 'mediaContent', $words > 300 ? 0 : 60, 'Chưa có ảnh hoặc video trong bài. Ảnh thật (phòng, địa điểm) giúp bài đáng tin và dễ đọc hơn.');
        } elseif ($total < $needed) {
            $this->setResult('readabilityAnalyses', 'mediaContent', 70, "Có $total ảnh/video cho $words từ — nên có khoảng $needed để chia nhịp bài.");
        } else {
            $this->setResult('readabilityAnalyses', 'mediaContent', 100, "Có $total ảnh/video minh họa, phù hợp độ dài bài.");
        }
    }

    protected function analyzeContentLength()
    {
        $wordCount = $this->countWords($this->plainText((string) $this->content));

        // Google không chấm theo số từ. Chỉ cảnh báo nội dung mỏng; bài dài hơn không được cộng thêm điểm.
        if ($wordCount === 0) {
            $this->setResult('analyses', 'contentLength', 0, 'Nội dung đang trống.');
        } elseif ($wordCount < 300) {
            $this->setResult('analyses', 'contentLength', 10, "Nội dung mỏng ($wordCount từ) — khó trả lời trọn vẹn câu hỏi người đọc. Bổ sung thông tin thực tế: giá, cách đặt, kinh nghiệm, câu hỏi thường gặp.");
        } elseif ($wordCount < 600) {
            $this->setResult('analyses', 'contentLength', 60, "Nội dung khá ngắn ($wordCount từ). Đủ nếu chủ đề hẹp; với chủ đề rộng nên bổ sung thông tin còn thiếu, không viết thêm cho đủ chữ.");
        } elseif ($wordCount > 3000) {
            $this->setResult('analyses', 'contentLength', 85, "Bài rất dài ($wordCount từ). Google không thưởng bài dài — đảm bảo không lan man, có mục lục H2 rõ ràng.");
        } else {
            $this->setResult('analyses', 'contentLength', 100, "Độ dài phù hợp ($wordCount từ). Google không chấm theo số từ — quan trọng là trả lời đủ ý, có thông tin thực tế.");
        }
    }

    protected function analyzeReadability()
    {
        $this->analyzeParagraphLength();
        $this->analyzeMediaContent();
    }

    protected function analyzeAdvancedSEO($currentRecordId = null)
    {
        $this->analyzeSubheadings();
        $this->analyzeImages();
        $this->analyzeKeywordDensity();
        $this->analyzeInternalLinks();
        $this->analyzeExternalLinks();
        $this->analyzeKeywordHistory($currentRecordId);
        $this->analyzeFreshness();

        // Chưa có nội dung thì các mục "không trùng / không lỗi / không cần link ngoài" chưa có ý nghĩa — để chờ
        // (không tính vào điểm) thay vì cho điểm tối đa.
        if ($this->plainText((string) $this->content) !== '') {
            $this->analyzeDuplicateContent($currentRecordId);
            $this->analyzeTechnical();
        } else {
            $this->advancedAnalyses['externalLinks'] = $this->defaultAnalysis('externalLinks');
        }
    }

    // Ghép thông báo: không có vấn đề → câu khen; có → liệt kê lỗi (bắt buộc sửa) rồi gợi ý.
    protected function composeMessage(string $okMessage, array $issues, array $tips = []): string
    {
        if (! $issues && ! $tips) {
            return $okMessage.'.';
        }

        $parts = [];
        if ($issues) {
            $parts[] = 'Cần sửa: '.implode('; ', $issues).'.';
        }
        if ($tips) {
            $parts[] = 'Gợi ý: '.implode('; ', $tips).'.';
        }

        return implode(' ', $parts);
    }

    protected function fieldContainsKeyword(string $field, string $keyword): bool
    {
        if (trim($keyword) === '' || trim($field) === '') {
            return false;
        }

        if ($this->isSlug($field)) {
            return $this->slugContainsKeyword($field, $keyword);
        }

        return str_contains($this->matchText($field), $this->matchText($keyword));
    }

    protected function slugContainsKeyword(string $slug, string $keyword): bool
    {
        $keywordSlug = $this->slugify($keyword);

        if ($keywordSlug !== '' && str_contains($slug, $keywordSlug)) {
            return true;
        }

        // Từ khóa lịch trình thường viết tắt 2N1Đ, còn URL nên viết đầy đủ để dễ đọc.
        $expanded = preg_replace_callback(
            '/(^|-)(\d+)n(\d+)d(?=-|$)/',
            fn (array $matches): string => $matches[1].$matches[2].'-ngay-'.$matches[3].'-dem',
            $keywordSlug
        );

        return $expanded !== $keywordSlug && str_contains($slug, $expanded);
    }

    protected function analyzeSecondaryKeywords(): void
    {
        $this->secondaryKeywordAnalyses = [];
        $secondaryKeywords = $this->getSecondaryKeywords();

        if (empty($secondaryKeywords)) {
            return;
        }

        $plainContent = $this->plainText((string) $this->content);
        $wordCount = $this->countWords($plainContent);
        $titleTag = $this->effectiveTitleTag();

        foreach ($secondaryKeywords as $keyword) {
            $inTitle       = $this->fieldContainsKeyword($titleTag.' '.$this->title, $keyword);
            $inDescription = $this->fieldContainsKeyword($this->description ?? '', $keyword);
            $inContent     = $this->fieldContainsKeyword($plainContent, $keyword);
            $inUrl         = $this->slug !== '' && $this->slugContainsKeyword($this->slug, $keyword);

            $count   = $this->countKeyword($keyword, $plainContent);
            $density = $wordCount > 0 ? round(($count / $wordCount) * 100, 2) : 0.0;

            // Từ khóa phụ chỉ cần xuất hiện tự nhiên trong nội dung (thường ở 1 tiêu đề phụ); không cần ép vào title/mô tả.
            if ($inContent && $count > 0 && $wordCount / $count < 60) {
                $status = 'warning';
                $message = "Lặp quá dày ($count lần) — giảm bớt để tránh nhồi từ khóa";
                $score = 50;
            } elseif ($inContent) {
                $status = 'success';
                $parts = array_filter([
                    $inTitle ? 'tiêu đề' : null,
                    $inDescription ? 'mô tả' : null,
                    'nội dung',
                ]);
                $message = 'Xuất hiện trong ' . implode(', ', $parts);
                $score = 100;
            } else {
                $status = 'error';
                $message = 'Chưa xuất hiện trong nội dung — thêm vào một tiêu đề phụ hoặc đoạn văn liên quan';
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
            'scoreNote'                => $this->scoreNote,
            'analyses'                 => $this->analyses,
            'advancedAnalyses'         => $this->advancedAnalyses,
            'readabilityAnalyses'      => $this->readabilityAnalyses,
            'secondaryKeywordAnalyses' => $this->secondaryKeywordAnalyses,
            'style'                    => $this->style,
        ]);
    }
}
