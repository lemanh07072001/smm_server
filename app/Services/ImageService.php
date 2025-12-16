<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageService
{
    /**
     * Upload and optimize image
     */
    public function upload(UploadedFile $file, string $folder, int $maxWidth = 800, int $quality = 80): string
    {
        $filename = Str::uuid() . '.webp';
        $path = $folder . '/' . $filename;

        // Get image info
        $imageInfo = getimagesize($file->getPathname());
        $mime = $imageInfo['mime'];
        $width = $imageInfo[0];
        $height = $imageInfo[1];

        // Create image resource based on mime type
        $source = match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($file->getPathname()),
            'image/png' => imagecreatefrompng($file->getPathname()),
            'image/gif' => imagecreatefromgif($file->getPathname()),
            'image/webp' => imagecreatefromwebp($file->getPathname()),
            default => throw new \Exception('Unsupported image type'),
        };

        // Calculate new dimensions
        if ($width > $maxWidth) {
            $newWidth = $maxWidth;
            $newHeight = (int) ($height * ($maxWidth / $width));
        } else {
            $newWidth = $width;
            $newHeight = $height;
        }

        // Create resized image
        $resized = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency for PNG
        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        // Resize
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        // Save to temp file as WebP
        $tempPath = sys_get_temp_dir() . '/' . $filename;
        imagewebp($resized, $tempPath, $quality);

        // Store to disk
        Storage::disk('public')->put($path, file_get_contents($tempPath));

        // Cleanup
        imagedestroy($source);
        imagedestroy($resized);
        unlink($tempPath);

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
