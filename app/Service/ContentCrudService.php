<?php

namespace App\Service;

use App\Interface\ContentRepositoryInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

class ContentCrudService
{
    protected $repo;
    protected $fileService;
    protected $imageService;

    public function __construct(
        ContentRepositoryInterface $repo,
        FileUploadService $fileService,
        ImageUploadService $imageService
    ) {
        $this->repo = $repo;
        $this->fileService = $fileService;
        $this->imageService = $imageService;
    }

    /**
     * Operational execution using the parameters decided by the controller.
     */
    public function createContentEntry(
        array $validatedData,
        string $imageFolder,
        string $fileFolder,
        int $maxWidth,
        int $maxHeight,
        string $fileTwoFolder = 'uploads/content/files'
    ): Model {
        $title = $validatedData['title'] ?? 'content';

        // 1. Handle Image Upload using controller-defined folder and 4000px dimensions
        if (isset($validatedData['image_path']) && $validatedData['image_path'] instanceof UploadedFile) {
            $customImageService = new ImageUploadService($maxWidth, $maxHeight);
            $validatedData['image_path'] = $customImageService->upload(
                $validatedData['image_path'],
                $title,
                $imageFolder
            );
        }

        // 2. Handle Generic File Upload using controller-defined folder
        if (isset($validatedData['file_path']) && $validatedData['file_path'] instanceof UploadedFile) {
            $validatedData['file_path'] = $this->fileService->upload(
                $validatedData['file_path'],
                $title,
                $fileFolder
            );
        }

        // 2b. Handle Second Optional File Upload with distinct suffix
        if (isset($validatedData['file_path_two']) && $validatedData['file_path_two'] instanceof UploadedFile) {
            $validatedData['file_path_two'] = $this->fileService->upload(
                $validatedData['file_path_two'],
                $title,
                $fileTwoFolder,
                '2'
            );
        }

        // 3. Store via Repository
        return $this->repo->Store($validatedData);
    }
}