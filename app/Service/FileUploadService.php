<?php

namespace App\Service;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class FileUploadService
{
    /**
     * Upload a generic file to the specified public directory with a title slug.
     */
    public function upload(
        UploadedFile $file,
        string $title,
        string $destinationPath = 'uploads/content/files',
        string $suffix = ''
    ): string {
        $titleSlug = Str::slug($title);
        $cleanSuffix = $suffix !== '' ? '-' . ltrim($suffix, '-') : '';
        $fileName = $titleSlug . '-' . time() . $cleanSuffix . '.' . $file->getClientOriginalExtension();

        $fullDestination = public_path($destinationPath);
        if (!file_exists($fullDestination)) {
            mkdir($fullDestination, 0755, true);
        }

        $file->move($fullDestination, $fileName);

        return $destinationPath . '/' . $fileName;
    }

// here perter: pure file-only upload using original filename + random string (no title, no suffix, no DB)
    public function uploadStandaloneFile(
        UploadedFile $file,
        string $destinationPath = 'uploads/content/files2'
    ): string {
        $baseName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $slugName = Str::slug($baseName) ?: 'file';
        $random = Str::lower(Str::random(6));
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');

        $fileName = $slugName . '-' . time() . '-' . $random . '.' . $ext;

        $cleanDest = ltrim($destinationPath, '/');
        $fullDestination = public_path($cleanDest);
        if (!file_exists($fullDestination)) {
            mkdir($fullDestination, 0755, true);
        }

        $file->move($fullDestination, $fileName);

        return $cleanDest . '/' . $fileName;
    }

    
}