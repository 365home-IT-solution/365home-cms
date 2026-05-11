<?php

namespace Modules\BladeThemeV1\Livewire;

use Livewire\Component;
use Modules\Comment\Entities\Comment as CommentProduct;
use Livewire\WithPagination;
use Modules\Comment\Entities\CommentReply;
use Modules\Product\App\Models\Product;

class Comment extends Component
{
    use WithPagination;

    public $paginationInfo;
    public $commentableId;
    public $commentableType;
    public $replyName;
    public $replyText;
    public $name;
    public $text;
    public $comments = [];
    public $replyErrors = [];
    public $showReplyModal = false;
    public $replyCommentId;
    public $replyCommentName;

    public function mount($commentableId, $commentableType)
    {
        $this->commentableId = $commentableId;
        $this->commentableType = $commentableType;
        $this->fetchComments();
    }

    public function openReplyModal($commentId, $commentName)
    {
        $this->showReplyModal = true;
        $this->replyCommentId = $commentId;
        $this->replyCommentName = $commentName;
    }

    public function closeReplyModal()
    {
        $this->showReplyModal = false;
        $this->reset(['replyName', 'replyText', 'replyCommentId', 'replyCommentName', 'replyErrors']);
    }

    public function submitReply()
    {
        $this->replyErrors = [];

        try {
            $validatedData = $this->validate([
                'replyName' => 'required|string|max:50|min:2',
                'replyText' => 'required|string|min:2',
            ], [
                'replyName.required' => 'Họ và Tên là bắt buộc.',
                'replyName.string' => 'Họ và Tên phải là một chuỗi ký tự.',
                'replyName.max' => 'Họ và Tên không được vượt quá :max ký tự.',
                'replyName.min' => 'Họ và Tên phải từ :min ký tự trở lên.',
                'replyText.required' => 'Nội dung là bắt buộc.',
                'replyText.string' => 'Nội dung phải là một chuỗi ký tự.',
                'replyText.min' => 'Nội dung phải từ :min ký tự trở lên.',
            ]);

            CommentReply::create([
                'name' => $validatedData['replyName'],
                'text' => $validatedData['replyText'],
                'comment_id' => $this->replyCommentId,
            ]);

            $this->reset(['replyName', 'replyText']);
            $this->closeReplyModal();

            $this->dispatch('show-message', [
                'type' => 'success',
                'message' => 'Phản hồi thành công!',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->replyErrors = $e->errors();
        } catch (\Exception $e) {
            $this->dispatch('show-message', [
                'type' => 'error',
                'message' => 'Đã có lỗi xảy ra khi thêm phản hồi. Vui lòng thử lại sau.',
            ]);
        }

        $this->fetchComments();
    }

    public function submit()
    {
        $this->validate([
            'name' => 'required|string|max:50|min:2',
            'text' => 'required|string|min:2',
        ], [
            'name.required' => 'Họ và Tên là bắt buộc.',
            'name.string' => 'Họ và Tên phải là một chuỗi ký tự.',
            'name.max' => 'Họ và Tên không được vượt quá :max ký tự.',
            'name.min' => 'Họ và Tên phải từ :min ký tự trở lên.',
            'text.required' => 'Nội dung là bắt buộc.',
            'text.string' => 'Nội dung phải là một chuỗi ký tự.',
            'text.min' => 'Nội dung phải từ :min ký tự trở lên.',
        ]);

        try {
            CommentProduct::create([
                'name' => $this->name,
                'text' => $this->text,
                'commentable_id' => $this->commentableId,
                'commentable_type' => $this->commentableType,
            ]);

            $this->reset(['name', 'text']);

            $this->dispatch('show-message', [
                'type' => 'success',
                'message' => 'Bình luận thêm thành công!',
            ]);
        } catch (\Exception $e) {
            $this->dispatch('show-message', [
                'type' => 'error',
                'message' => 'Đã có lỗi xảy ra khi thêm bình luận. Vui lòng thử lại sau.',
            ]);
        }

        $this->fetchComments();
    }
    public function fetchComments()
    {
        $paginatedComments = CommentProduct::with(['replies' => function ($query) {
            $query->where('show', true)->orderBy('pin', 'desc')->orderBy('created_at', 'desc');
        }])
            ->where('commentable_id', $this->commentableId)
            ->where('commentable_type', $this->commentableType)
            ->where('show', true)
            ->orderBy('pin', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(5);

        $this->comments = $paginatedComments->items();
        $this->paginationInfo = [
            'currentPage' => $paginatedComments->currentPage(),
            'lastPage' => $paginatedComments->lastPage(),
            'perPage' => $paginatedComments->perPage(),
            'total' => $paginatedComments->total(),
        ];
    }

    public function gotoPage($page)
    {
        $this->setPage($page);
        $this->fetchComments();
    }

    public function render()
    {
        return view('bladethemev1::livewire.comment', [
            'isProduct' => $this->commentableType === 'Modules\Product\App\Models\Product',
            'isPost' => $this->commentableType === 'Modules\Post\Entities\Post',
            'comments' => $this->comments,
            'paginationInfo' => $this->paginationInfo,
        ]);
    }
}
