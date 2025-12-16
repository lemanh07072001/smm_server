<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageService
{
    /**
     * Upload image (simple version without resize)
     */
    public function upload(UploadedFile $file, string $folder, int $maxWidth = 800, int $quality = 80): string
    {
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid() . '.' . $extension;
        $path = $folder . '/' . $filename;

        Storage::disk('public')->put($path, file_get_contents($file->getPathname()));

        return $path;
    }

    /**
     * Delete image from storage
     */
    public function delete(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }
}
