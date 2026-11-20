<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreContentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255|unique:contents,title',
            'content' => 'required|string',
            'keywords' => 'nullable|string|max:1000',
            'url_one' => 'nullable|url|max:255',
            'url_two' => 'nullable|url|max:255',
            'url_three' => 'nullable|url|max:255',
            'file_path' => 'nullable|file|max:20480', // max 20MB
            'image_path' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:51200', // max 50MB for high-res AI images
        ];
    }
}