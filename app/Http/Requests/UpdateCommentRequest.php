<?php

namespace App\Http\Requests;

use App\Models\Comment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCommentRequest extends FormRequest
{
    /**
     * Only the comment's own author may edit it — not even the project
     * owner (see `CommentPolicy`).
     */
    public function authorize(): bool
    {
        /** @var Comment $comment */
        $comment = $this->route('comment');

        return $this->user()?->can('update', $comment) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'El comentario no puede estar vacío.',
        ];
    }
}
