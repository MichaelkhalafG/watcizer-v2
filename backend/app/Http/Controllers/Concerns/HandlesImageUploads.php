<?php

namespace App\Http\Controllers\Concerns;

use App\Exceptions\ImageProcessingException;
use App\Services\ImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Shared image-upload handling for admin controllers.
 *
 * Centralises the "process the NEW image first, delete the OLD one only after
 * that succeeds, fail gracefully" pattern so a heavy/broken upload can never
 * (a) 500 the request, (b) leave a record with no image, or (c) lose the form.
 */
trait HandlesImageUploads
{
    /**
     * Process a required/replacement image and return the stored filename.
     *
     * The new image is processed FIRST; $oldFilename (if any) is deleted only
     * AFTER that succeeds, so a failed upload never leaves a record imageless.
     * On failure throws ImageProcessingException, which the exception handler
     * renders as a friendly back()->withInput() redirect (consistent message
     * across the whole admin).
     */
    protected function processImageOrFail(
        UploadedFile $file,
        string $folder,
        array $options,
        ?string $oldFilename = null,
        string $field = 'image'
    ): string {
        $newName = $this->tryProcessImage($file, $folder, $options);

        if ($newName === null) {
            throw new ImageProcessingException($field);
        }

        // Old file removed only now that the replacement exists on disk.
        $this->deleteUploadedImage($folder, $oldFilename);

        return $newName;
    }

    /**
     * Process an image, returning the stored filename, or null on failure (the
     * failure is logged). Use inside multi-image loops where one bad file should
     * be skipped rather than aborting the whole batch.
     */
    protected function tryProcessImage(UploadedFile $file, string $folder, array $options): ?string
    {
        try {
            return (new ImageService)->process($file, $folder, $options);
        } catch (\Throwable $e) {
            Log::error("Admin image processing failed [{$folder}]: " . $e->getMessage());

            return null;
        }
    }

    /** Delete a previously-stored upload if it exists (no-op on null/missing). */
    protected function deleteUploadedImage(string $folder, ?string $filename): void
    {
        if (! $filename) {
            return;
        }

        $path = public_path('Uploads_Images/' . trim($folder, '/') . '/' . $filename);
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
