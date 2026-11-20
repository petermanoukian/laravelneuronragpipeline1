<?php

namespace App\Service;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class ImageUploadService
{
    protected int $maxWidth;
    protected int $maxHeight;

    /**
     * Constructor allowing us to overwrite default max values (e.g., 4000px for AI).
     */
    public function __construct(int $maxWidth = 4000, int $maxHeight = 4000)
    {
        $this->maxWidth = $maxWidth;
        $this->maxHeight = $maxHeight;
    }

    /**
     * Upload, resize, and store a high-resolution image.
     */
    public function upload(UploadedFile $file, string $title, string $destinationPath = 'uploads/content/img'): string
    {
        $titleSlug = Str::slug($title);
        $imageName = $titleSlug . '-' . time() . '.' . $file->getClientOriginalExtension();
        
        $fullDestination = public_path($destinationPath);
        if (!file_exists($fullDestination)) {
            mkdir($fullDestination, 0755, true);
        }

        if (class_exists(Image::class)) {
            $imgInstance = Image::make($file);
            $imgInstance->resize($this->maxWidth, $this->maxHeight, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            })->save($fullDestination . '/' . $imageName);
        } else {
            $file->move($fullDestination, $imageName);
        }

        return $destinationPath . '/' . $imageName;
    }
}