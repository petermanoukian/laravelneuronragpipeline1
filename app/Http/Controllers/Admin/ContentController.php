<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreContentRequest;
use App\Service\ContentCrudService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

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
}