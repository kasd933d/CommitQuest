<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarkNotificationsReadRequest extends FormRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            // Mark the listed ids read; when omitted, every unread one is marked.
            'ids' => ['sometimes', 'array', 'max:100'],
            'ids.*' => ['integer', 'min:1'],
        ];
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }
}
