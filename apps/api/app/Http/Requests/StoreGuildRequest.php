<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreGuildRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_public' => ['sometimes', 'boolean'],
        ];
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }
}
