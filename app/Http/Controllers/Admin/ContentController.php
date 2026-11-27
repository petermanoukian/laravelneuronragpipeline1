<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContentRequest;
use App\Http\Requests\UpdateContentRequest;
use App\Service\ContentCrudService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Illuminate\Http\Request; // here perter

class ContentController extends Controller
{
    protected ContentCrudService $crudService;

    public function __construct(ContentCrudService $crudService)
    {
        $this->crudService = $crudService;
    }

    /**
     * Show the form for creating new RAG content.
     */
    public function create(): View
    {
        return view('private.create'); 
    }

    /**
     * Store/Add a newly created content entry.
     */
    public function add(StoreContentRequest $request): RedirectResponse
    {
        // Controller decides the operational parameters:
        $imageFolder    = 'uploads/content/img';
        $fileFolder     = 'uploads/content/files';
        $fileTwoFolder  = 'uploads/content/files';
        $maxWidth       = 4000;
        $maxHeight      = 4000;

        // Delegate execution to the operational service, passing the controller's decisions
        $content = $this->crudService->createContentEntry(
            $request->validated(),
            $imageFolder,
            $fileFolder,
            $maxWidth,
            $maxHeight,
            $fileTwoFolder
        );

        return redirect()->back()->with('success', 'Content successfully created with ID: ' . $content->id);
    }


// here perter: standalone file-only upload form (no database)
    public function createfile(): View
    {
        return view('private.createfiles');
    }

    // here perter: delegate file-only upload to ContentCrudService (no database)
    public function filesStore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'files'   => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'file', 'mimes:pdf,txt,md,rtf,csv,xlsx,xls,doc,docx', 'max:20480'],
        ]);

        $fileFolder = 'uploads/content/files2';
        $uploadedPaths = $this->crudService->storeStandaloneFiles($validated['files'], $fileFolder);

        return redirect()
            ->route('admin.contents.files.create')
            ->with('success', 'Uploaded ' . count($uploadedPaths) . ' file(s) to ' . $fileFolder . '.')
            ->with('uploaded_files', $uploadedPaths);
    }

    
    public function index(): View
    {
        $contents = $this->crudService->getPaginatedContentsForAdmin(15);
        $standaloneFiles = $this->crudService->getStandaloneFiles('uploads/content/files2');

        return view('private.viewcontent', [
            'contents'        => $contents,
            'standaloneFiles' => $standaloneFiles,
        ]);
    }

    public function edit(int $id): View|RedirectResponse
    {
        $content = $this->crudService->getContentById($id);
        if (!$content) {
            return redirect()->route('admin.contents.index')->with('error', 'Content record not found.');
        }

        return view('private.editcontent', [
            'content' => $content,
        ]);
    }

    public function update(UpdateContentRequest $request, int $id): RedirectResponse
    {
        $imageFolder   = 'uploads/content/img';
        $fileFolder    = 'uploads/content/files';
        $fileTwoFolder = 'uploads/content/files';
        $maxWidth      = 4000;
        $maxHeight     = 4000;

        $updated = $this->crudService->updateContentEntry(
            $id,
            $request->validated(),
            $imageFolder,
            $fileFolder,
            $maxWidth,
            $maxHeight,
            $fileTwoFolder
        );

        if (!$updated) {
            return redirect()->route('admin.contents.viewfiles')->with('error', 'Content record not found.');
        }

        return redirect()->route('admin.contents.viewfiles')->with('success', 'Content #' . $id . ' updated.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $deleted = $this->crudService->deleteContentEntry($id);

        if (!$deleted) {
            return redirect()->route('admin.contents.viewfiles')->with('error', 'Content record not found.');
        }

        return redirect()->route('admin.contents.viewfiles')->with('success', 'Content #' . $id . ' deleted.');
    }

    public function deleteStandaloneFile(string $filename): RedirectResponse
    {
        $fileFolder = 'uploads/content/files2';
        $deleted = $this->crudService->deleteStandaloneFile($filename, $fileFolder);

        if (!$deleted) {
            return redirect()->route('admin.contents.viewfiles')->with('error', 'Standalone file not found.');
        }

        return redirect()->route('admin.contents.viewfiles')->with('success', 'File "' . $filename . '" deleted.');
    }


}