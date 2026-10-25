<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'timezone' => ['sometimes', 'string', 'max:64', function (string $attribute, mixed $value, callable $fail) {
                if (! in_array($value, \DateTimeZone::listIdentifiers(), true)) {
                    $fail('The timezone must be a valid IANA timezone identifier, e.g. "Asia/Karachi".');
                }
            }],
            'is_public' => ['sometimes', 'boolean'],
        ];
    }
}
