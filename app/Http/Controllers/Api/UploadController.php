<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadImageRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    /**
     * Upload an image.
     */
    public function uploadImage(UploadImageRequest $request): JsonResponse
    {
        try {
            $image = $request->file('image');

            // Generate unique filename
            $filename = time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();

            // Store image in public/images directory
            $path = $image->storeAs('images', $filename, 'public');

            // Generate full URL
            $url = asset('storage/' . $path);

            return response()->json([
                'message' => 'Upload ảnh thành công.',
                'data' => [
                    'filename' => $filename,
                    'path' => $path,
                    'url' => $url,
                    'size' => $image->getSize(),
                    'mime_type' => $image->getMimeType(),
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Upload ảnh thất bại.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload multiple images.
     */
    public function uploadMultipleImages(UploadImageRequest $request): JsonResponse
    {
        try {
            $images = $request->file('images');
            $uploadedImages = [];

            foreach ($images as $image) {
                // Generate unique filename
                $filename = time() . '_' . Str::random(10) . '.' . $image->getClientOriginalExtension();

                // Store image in public/images directory
                $path = $image->storeAs('images', $filename, 'public');

                // Generate full URL
                $url = asset('storage/' . $path);

                $uploadedImages[] = [
                    'filename' => $filename,
                    'path' => $path,
                    'url' => $url,
                    'size' => $image->getSize(),
                    'mime_type' => $image->getMimeType(),
                ];
            }

            return response()->json([
                'message' => 'Upload ' . count($uploadedImages) . ' ảnh thành công.',
                'data' => $uploadedImages,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Upload ảnh thất bại.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete an image.
     */
    public function deleteImage(string $filename): JsonResponse
    {
        try {
            $path = 'images/' . $filename;

            if (!Storage::disk('public')->exists($path)) {
                return response()->json([
                    'message' => 'Ảnh không tồn tại.',
                ], 404);
            }

            Storage::disk('public')->delete($path);

            return response()->json([
                'message' => 'Xóa ảnh thành công.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Xóa ảnh thất bại.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
