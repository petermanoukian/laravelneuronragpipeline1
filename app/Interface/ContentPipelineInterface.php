<?php

namespace App\Interface;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface ContentPipelineInterface
{
    /**
     * Pushes a single content entry to specified AI processors for analysis.
     * The processors analyze files/links and return extracted text or context.
     * This function is responsible for Updating the main table's 'content' and 'description' fields with the AI output.
     *
     * @param int $id The content entry ID to process.
     * @param array $processors [string...] An array of AI models/processors to use (e.g. ['gemini_1.5', 'claude_3_context']).
     * @return Model The updated content entry model.
     */
    public function ProcessContentEntry(int $id, array $processors);

    /**
     * Initializes the content processing pipeline for a list or collection of content entries.
     * Used for batch processing. This function loops and calls ProcessContentEntry for each provided entry.
     *
     * @param array|Collection $entries An array or collection of entry IDs or Model instances.
     * @return bool True if at least one entry was successfully initialized.
     */
    public function InitializeContentPipeline($entries);
}