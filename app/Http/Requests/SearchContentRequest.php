<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SearchContentRequest extends FormRequest
{
    /**
     * Public search endpoint is accessible to all visitors.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the search request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => 'nullable|string|max:500',
        ];
    }
}