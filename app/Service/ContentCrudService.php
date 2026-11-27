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


    // here perter: standalone file upload without DB persistence
    /**
     * @param  array<int, \Illuminate\Http\UploadedFile>  $files
     * @return array<int, string>
     */
    public function storeStandaloneFiles(array $files, string $fileFolder = 'uploads/content/files2'): array
    {
        $paths = [];

        foreach ($files as $file) {
            if ($file instanceof \Illuminate\Http\UploadedFile) {
                $paths[] = $this->fileService->uploadStandaloneFile($file, $fileFolder);
            }
        }

        return $paths;
    }


// here perter: paginated DB content entries for admin view (id + title)
    public function getPaginatedContentsForAdmin(int $perPage = 15)
    {
        return $this->repo->ReturnManyPaginated(
            filters: [],
            orderBy: 'id',
            orderDir: 'desc',
            related: [],
            fields: ['id', 'title'],
            perPage: $perPage
        );
    }

    // here perter: retrieve single DB content record by ID for editing
    public function getContentById(int $id): ?Model
    {
        return $this->repo->ReturnOneByID($id);
    }

    // here perter: update an existing DB content entry and optional uploaded media
    public function updateContentEntry(
        int $id,
        array $validatedData,
        string $imageFolder,
        string $fileFolder,
        int $maxWidth,
        int $maxHeight,
        string $fileTwoFolder = 'uploads/content/files'
    ): Model|false {
        $existing = $this->repo->ReturnOneByID($id);
        if (!$existing) {
            return false;
        }

        $title = $validatedData['title'] ?? ($existing->title ?? 'content');

        if (isset($validatedData['image_path']) && $validatedData['image_path'] instanceof UploadedFile) {
            $customImageService = new ImageUploadService($maxWidth, $maxHeight);
            $validatedData['image_path'] = $customImageService->upload(
                $validatedData['image_path'],
                $title,
                $imageFolder
            );
        } else {
            unset($validatedData['image_path']);
        }

        if (isset($validatedData['file_path']) && $validatedData['file_path'] instanceof UploadedFile) {
            $validatedData['file_path'] = $this->fileService->upload(
                $validatedData['file_path'],
                $title,
                $fileFolder
            );
        } else {
            unset($validatedData['file_path']);
        }

        if (isset($validatedData['file_path_two']) && $validatedData['file_path_two'] instanceof UploadedFile) {
            $validatedData['file_path_two'] = $this->fileService->upload(
                $validatedData['file_path_two'],
                $title,
                $fileTwoFolder,
                '2'
            );
        } else {
            unset($validatedData['file_path_two']);
        }

        return $this->repo->Update($id, $validatedData);
    }

    // here perter: delete a DB content record via repository
    public function deleteContentEntry(int $id): bool
    {
        return (bool) $this->repo->Delete($id);
    }

    // here perter: list standalone files in uploads/content/files2 without touching the DB
    /**
     * @return array<int, array{filename: string, relative_path: string, extension: string}>
     */
    public function getStandaloneFiles(string $fileFolder = 'uploads/content/files2'): array
    {
        $cleanFolder = trim(str_replace('\\', '/', $fileFolder), '/');
        $fullDir = public_path($cleanFolder);

        if (!\Illuminate\Support\Facades\File::isDirectory($fullDir)) {
            return [];
        }

        $items = [];
        foreach (\Illuminate\Support\Facades\File::files($fullDir) as $fileInfo) {
            $items[] = [
                'filename'      => $fileInfo->getFilename(),
                'relative_path' => $cleanFolder . '/' . $fileInfo->getFilename(),
                'extension'     => strtolower($fileInfo->getExtension()),
            ];
        }

        return $items;
    }


    public function deleteStandaloneFile(string $filename, string $fileFolder = 'uploads/content/files2'): bool
    {
        $safeName = basename($filename);
        if ($safeName === '' || $safeName !== $filename) {
            return false;
        }

        $cleanFolder = trim(str_replace('\\', '/', $fileFolder), '/');
        $baseDir = realpath(public_path($cleanFolder));
        if ($baseDir === false) {
            return false;
        }

        $targetPath = realpath($baseDir . DIRECTORY_SEPARATOR . $safeName);
        if ($targetPath === false || !str_starts_with($targetPath, $baseDir . DIRECTORY_SEPARATOR)) {
            return false;
        }

        if (!\Illuminate\Support\Facades\File::isFile($targetPath)) {
            return false;
        }

        return \Illuminate\Support\Facades\File::delete($targetPath);
    }



}