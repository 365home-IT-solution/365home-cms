<?php

declare(strict_types=1);

namespace Modules\QA\App\Filament\Resources\QAResource\Forms;

use Filament\Forms\Components\Card;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\Str;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use OpenAI\Laravel\Facades\OpenAI;

class QAForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Thông tin chung')
                    ->description('Thiết lập thông tin cơ bản cho chuyên mục Q&A')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextInput::make('name')
                                    ->label('Tên chuyên mục')
                                    ->required()
                                    ->minLength(3)
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(
                                        fn(Get $get, Set $set, ?string $state) =>
                                        !$get('slug') && $state ? $set('slug', Str::slug($state)) : null
                                    )
                                    ->placeholder('Ví dụ: Câu hỏi thường gặp về sản phẩm')
                                    ->suffixIcon('heroicon-m-pencil'),

                                TextInput::make('slug')
                                    ->label('Đường dẫn')
                                    ->required()
                                    ->unique(ignoreRecord: true)
                                    ->maxLength(255)
                                    ->rules(['alpha_dash'])
                                    ->helperText('Đường dẫn này sẽ được sử dụng trong URL')
                                    ->placeholder('cau-hoi-thuong-gap-ve-san-pham')
                                    ->suffixIcon('heroicon-m-link'),

                                TagsInput::make('categories')
                                    ->label('Danh mục')
                                    ->placeholder('Thêm danh mục')
                                    ->helperText('Thêm các danh mục liên quan')
                                    ->suggestions([
                                        'Sản phẩm',
                                        'Dịch vụ',
                                        'Kỹ thuật',
                                        'Chung'
                                    ]),

                                Select::make('status')
                                    ->label('Trạng thái')
                                    ->options([
                                        'draft' => 'Bản nháp',
                                        'published' => 'Đã xuất bản',
                                        'archived' => 'Đã lưu trữ'
                                    ])
                                    ->default('draft')
                                    ->required(),
                            ]),
                    ])
                    ->collapsible(),

                Section::make('Danh sách Q&A')
                    ->description('Quản lý danh sách câu hỏi và trả lời')
                    ->schema([
                        Repeater::make('qa_data')
                            ->schema([
                                Card::make()
                                    ->schema([
                                        TextInput::make('question')
                                            ->label('Câu hỏi')
                                            ->required()
                                            ->minLength(10)
                                            ->maxLength(500)
                                            ->placeholder('Nhập câu hỏi của bạn tại đây')
                                            ->columnSpan('full')
                                            ->suffixAction(
                                                Action::make('generateAnswer')
                                                    ->icon('heroicon-m-sparkles')
                                                    ->tooltip('Tạo câu trả lời tự động bằng AI')
                                                    ->action(function (Get $get, Set $set, $state) {
                                                        try {
                                                            $question = $get('question');

                                                            if (empty($question)) {
                                                                Notification::make()
                                                                    ->warning()
                                                                    ->title('Vui lòng nhập câu hỏi trước')
                                                                    ->send();
                                                                return;
                                                            }

                                                            // Sử dụng mô hình BLOOM hoặc T5 để có câu trả lời tốt hơn
                                                            $response = Http::withHeaders([
                                                                'Authorization' => 'Bearer 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',
                                                                'Content-Type' => 'application/json',
                                                            ])->post('https://api-inference.huggingface.co/models/bigscience/bloom', [
                                                                'inputs' => "Trả lời câu hỏi sau một cách chuyên nghiệp và ngắn gọn nhất: " . $question,
                                                                'parameters' => [
                                                                    'max_new_tokens' => 500,
                                                                    'temperature' => 0.7,
                                                                    'top_p' => 0.9,
                                                                    'do_sample' => true,
                                                                    'return_full_text' => false,
                                                                ],
                                                            ]);

                                                            if ($response->failed()) {
                                                                // Thử với mô hình dự phòng nếu mô hình đầu tiên thất bại
                                                                $response = Http::withHeaders([
                                                                    'Authorization' => 'Bearer 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',
                                                                    'Content-Type' => 'application/json',
                                                                ])->post('https://api-inference.huggingface.co/models/VietAI/vit5-base', [
                                                                    'inputs' => "Trả lời câu hỏi sau một cách chuyên nghiệp: " . $question,
                                                                    'parameters' => [
                                                                        'max_new_tokens' => 500,
                                                                        'temperature' => 0.7,
                                                                    ],
                                                                ]);

                                                                if ($response->failed()) {
                                                                    throw new \Exception('Không thể kết nối với các mô hình AI. Vui lòng thử lại sau.');
                                                                }
                                                            }

                                                            $result = $response->json();
                                                            
                                                            // Xử lý kết quả tùy theo format trả về của mô hình
                                                            $answer = is_array($result) ? 
                                                                (isset($result[0]['generated_text']) ? $result[0]['generated_text'] : $result[0]) :
                                                                $result;

                                                            // Làm sạch câu trả lời
                                                            $answer = preg_replace('/^(Context:|Question:|Answer:)/im', '', $answer);
                                                            $answer = trim($answer);

                                                            if (empty($answer)) {
                                                                throw new \Exception('Không nhận được câu trả lời hợp lệ từ AI.');
                                                            }

                                                            $set('answer', $answer);

                                                            Notification::make()
                                                                ->success()
                                                                ->title('Đã tạo câu trả lời bằng AI')
                                                                ->send();

                                                        } catch (\Exception $e) {
                                                            Notification::make()
                                                                ->danger()
                                                                ->title('Có lỗi xảy ra')
                                                                ->body($e->getMessage())
                                                                ->send();
                                                        }
                                                    })
                                            ),

                                        RichEditor::make('answer')
                                            ->label('Câu trả lời')
                                            ->required()
                                            ->minLength(20)
                                            ->placeholder('Cung cấp câu trả lời chi tiết')
                                            ->columnSpan('full'),

                                        Grid::make(2)
                                            ->schema([
                                                Toggle::make('is_visible')
                                                    ->label('Hiển thị công khai')
                                                    ->default(true)
                                                    ->helperText('Bật/tắt để hiện/ẩn mục này'),

                                                Toggle::make('is_featured')
                                                    ->label('Câu hỏi nổi bật')
                                                    ->default(false)
                                                    ->helperText('Đánh dấu là câu hỏi quan trọng'),
                                            ]),
                                    ])
                                    ->columns(1),
                            ])
                            ->createItemButtonLabel('Thêm câu hỏi mới')
                            ->itemLabel(function (array $state): ?string {
                                $question = $state['question'] ?? '';
                                $isVisible = $state['is_visible'] ?? true;
                                $isFeatured = $state['is_featured'] ?? false;

                                $status = [];
                                if (!$isVisible) $status[] = '🔒';
                                if ($isFeatured) $status[] = '⭐';

                                $truncatedQuestion = strlen($question) > 50
                                    ? substr($question, 0, 47) . '...'
                                    : $question;

                                return trim(implode(' ', $status) . ' ' . $truncatedQuestion);
                            })
                            ->collapsible()
                            ->cloneable()
                            ->reorderable()
                            ->defaultItems(1)
                            ->grid(1)
                            ->collapsed()
                            ->deleteAction(
                                fn(Action $action) => $action->requiresConfirmation()
                            )
                            ->columnSpan('full'),
                    ]),
            ]);
    }
}
