<?php

namespace Modules\BladeThemeV1\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class OcrSpaceService
{
    protected $apiKey;
    protected $apiUrl = 'https://api.ocr.space/parse/image';

    public function __construct()
    {
        $this->apiKey = config('services.ocr_space.api_key');

        if (!$this->apiKey) {
            Log::warning('OCR.space API Key not configured in .env');
        }
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    public function extractTextFromImage(string $imagePath): string
    {
        if (!$this->isConfigured()) {
            return '';
        }

        try {
            if (!file_exists($imagePath)) {
                Log::error("Image file not found for OCR: {$imagePath}");
                return '';
            }

            $imageContent = $this->getOptimizedImageContent($imagePath);

            if (strlen($imageContent) > 1024 * 1024) {
                Log::warning('Image still exceeds 1MB after optimization, OCR may fail', [
                    'size' => strlen($imageContent)
                ]);
            }
            $response = Http::timeout(30)->attach(
                'file',
                $imageContent,
                basename($imagePath)
            )->post($this->apiUrl, [
                        'apikey' => $this->apiKey,
                        'language' => 'auto',
                        'isOverlayRequired' => 'false',
                        'detectOrientation' => 'true',
                        'scale' => 'true',
                        'OCREngine' => '2',
                    ]);

            if ($response->successful()) {
                $result = $response->json();

                if (isset($result['ParsedResults'][0]['ParsedText'])) {
                    $extractedText = $result['ParsedResults'][0]['ParsedText'];

                    Log::info('Text extracted successfully via OCR.space', [
                        'image' => basename($imagePath),
                        'text_length' => strlen($extractedText)
                    ]);

                    return $extractedText;
                }

                if (isset($result['ErrorMessage'])) {
                    Log::error('OCR.space API Error', [
                        'error' => $result['ErrorMessage']
                    ]);
                }
            } else {
                Log::error('OCR.space Request Failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
            }

            return '';

        } catch (Exception $e) {
            Log::error('Failed to extract text via OCR.space', [
                'image' => basename($imagePath),
                'error' => $e->getMessage()
            ]);
            return '';
        }
    }

    public function formatCccdInfo(string $frontText, string $backText = ''): string
    {
        if (empty($frontText)) {
            return '';
        }

        $lines = explode("\n", $frontText);
        $extracted = [
            'Name' => 'N/A',
            'ID' => 'N/A',
            'Home' => 'N/A'
        ];

        if (preg_match('/\b(\d{12})\b/', $frontText, $matches)) {
            $extracted['ID'] = $matches[1];
        }

        $keywords = [
            'Họ và tên',
            'Full name',
            'Ngày sinh',
            'Date of birth',
            'Giới tính',
            'Sex',
            'Quốc tịch',
            'Nationality',
            'Quê quán',
            'Place of origin',
            'Nơi thường trú',
            'Place of residence',
            'Số',
            'No.',
            'Có giá trị đến',
            'Date of expiry',
            'Date of oxpiry',
            'Expiry'
        ];

        foreach ($lines as $i => $line) {
            $line = trim($line);
            if (empty($line))
                continue;

            if ($extracted['Name'] === 'N/A') {
                if (preg_match('/(?:Họ và tên\s*[\/\x5C]\s*Full name|Họ và tên|Full name)[:\s\/\x5C]+([^;,\n]*)/ui', $line, $nameMatches)) {
                    $val = trim($nameMatches[1]);
                    if (strlen($val) > 2 && !preg_match('/^(?:[\/\x5C]|Full name|:|\s)+$/i', $val)) {
                        $extracted['Name'] = $val;
                    } elseif (isset($lines[$i + 1])) {
                        $extracted['Name'] = trim($lines[$i + 1]);
                    }
                }
            }

            if ($extracted['Home'] === 'N/A') {
                if (preg_match('/(?:Nơi thường trú\s*[\/\x5C]\s*Place of residence|Nơi thường trú|Place of residence)[:\s\/\x5C]+([^;,\n]*)/ui', $line, $homeMatches)) {
                    $val = trim($homeMatches[1]);
                    $homeLines = [];

                    if (strlen($val) > 2 && !preg_match('/^(?:[\/\x5C]|Place of residence|:|\s)+$/i', $val)) {
                        $homeLines[] = $val;
                    }

                    for ($j = 1; $j <= 3; $j++) {
                        if (isset($lines[$i + $j])) {
                            $nextLine = trim($lines[$i + $j]);
                            if (empty($nextLine) || strlen($nextLine) < 2)
                                continue;

                            if (preg_match('/(?:Họ và tên|Số CCCD)/ui', $nextLine)) {
                                break;
                            }

                            $cleanLine = preg_replace('/(?:Có giá trị đến|Date of ex?piry|Date of oxpir[yv]|Expiry).*$/ui', '', $nextLine);
                            $cleanLine = trim($cleanLine, " ,/\\");

                            if (!empty($cleanLine)) {
                                $homeLines[] = $cleanLine;
                            }
                        }
                    }

                    if (!empty($homeLines)) {
                        $extracted['Home'] = implode(', ', $homeLines);
                    }
                }
            }
        }

        $result = [];
        $result[] = "Họ tên: " . $extracted['Name'];
        $result[] = "Số CCCD: " . $extracted['ID'];
        $result[] = "Nơi thường trú: " . $extracted['Home'];

        return implode("\n", $result);
    }

    protected function getOptimizedImageContent(string $path): string
    {
        $fileSize = filesize($path);

        if ($fileSize <= 1024 * 1024) {
            return file_get_contents($path);
        }

        try {
            Log::info("Compressing image for OCR.space", ['original_size' => $fileSize]);

            $info = getimagesize($path);
            if (!$info)
                return file_get_contents($path);

            $mime = $info['mime'];

            switch ($mime) {
                case 'image/jpeg':
                    $image = imagecreatefromjpeg($path);
                    break;
                case 'image/png':
                    $image = imagecreatefrompng($path);
                    break;
                default:
                    return file_get_contents($path);
            }

            if (!$image)
                return file_get_contents($path);

            $width = imagesx($image);
            $height = imagesy($image);

            $maxDimension = 1600;
            if ($width > $maxDimension || $height > $maxDimension) {
                if ($width > $height) {
                    $newWidth = $maxDimension;
                    $newHeight = floor($height * ($maxDimension / $width));
                } else {
                    $newHeight = $maxDimension;
                    $newWidth = floor($width * ($maxDimension / $height));
                }

                $newImage = imagecreatetruecolor($newWidth, $newHeight);
                imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                imagedestroy($image);
                $image = $newImage;
            }

            ob_start();
            imagejpeg($image, null, 75);
            $content = ob_get_clean();

            imagedestroy($image);

            Log::info("Compression finished", ['new_size' => strlen($content)]);
            return $content;

        } catch (Exception $e) {
            Log::error("Failed to compress image", ['error' => $e->getMessage()]);
            return file_get_contents($path);
        }
    }
}
