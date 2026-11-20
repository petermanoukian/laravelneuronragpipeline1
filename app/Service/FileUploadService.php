<?php

namespace App\Service;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class FileUploadService
{
    /**
     * Upload a generic file to the specified public directory with a title slug.
     */
    public function upload(UploadedFile $file, string $title, string $destinationPath = 'uploads/content/files'): string
    {
        $titleSlug = Str::slug($title);
        $fileName = $titleSlug . '-' . time() . '.' . $file->getClientOriginalExtension();
        
        $fullDestination = public_path($destinationPath);
        if (!file_exists($fullDestination)) {
            mkdir($fullDestination, 0755, true);
        }

        $file->move($fullDestination, $fileName);

        return $destinationPath . '/' . $fileName;
    }
}