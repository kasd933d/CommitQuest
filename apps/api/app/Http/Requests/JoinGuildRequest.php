<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JoinGuildRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Required only for private guilds; validated in GuildService.
            'invite_code' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function authorize(): bool
    {
        return $this->user() !== null;
    }
}
