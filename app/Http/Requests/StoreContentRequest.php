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
        $allowedDocs = 'pdf,jpg,jpeg,png,gif,webp,txt,md,json,html,doc,docx,rtf,csv,xls,xlsx';

        return [
            'title'         => 'required|string|max:255|unique:contents,title',
            'content'       => 'required|string',
            'keywords'      => 'nullable|string|max:1000',
            'url_one'       => 'nullable|url|max:255',
            'url_two'       => 'nullable|url|max:255',
            'url_three'     => 'nullable|url|max:255',
            'file_path'     => "nullable|file|mimes:{$allowedDocs}|max:20480",
            'file_path_two' => "nullable|file|mimes:{$allowedDocs}|max:20480",
            'image_path'    => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:51200',
        ];
    }

}