<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RunAnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'min:10'],
        ];
    }

    public function messages(): array
    {
        return [
            'token.required' => 'يجب إدخال Thndr API Token.',
            'token.min'      => 'Token غير صالح.',
        ];
    }
}
