<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CategoryController extends Controller
{
    public function __construct(
        private ImageService $imageService
    ) {}
    public function index(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 10);
        $page = $request->input('page', 1);
        $search = $request->input('search');
        $status = $request->input('is_active');

        $query = Category::orderBy('sort_order');

        // Filter by search
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($status !== null) {
            $query->where('is_active', $status === '1' || $status === 'true' ? 1 : 0);
        }

        $total = $query->count();
        $totalPages = (int) ceil($total / $limit);

        $categories = $query->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return response()->json([
            'data' => $categories,
            'total' => $total,
            'page' => (int) $page,
            'limit' => (int) $limit,
            'totalPages' => $totalPages,
        ]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        unset($data['image']);

        if ($request->hasFile('image')) {
            $data['image'] = $this->imageService->upload($request->file('image'), 'categories', 400, 85);
        }

        $category = Category::create($data);
        Cache::forget('categories_active');

        return response()->json([
            'message' => 'Tạo danh mục thành công.',
            'data' => $category,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        return response()->json([
            'data' => $category,
        ]);
    }

    public function update(UpdateCategoryRequest $request, int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        $data = $request->validated();
        unset($data['image']);

        if ($request->hasFile('image')) {
            $this->imageService->delete($category->image);
            $data['image'] = $this->imageService->upload($request->file('image'), 'categories', 400, 85);
        }

        $category->update($data);
        Cache::forget('categories_active');

        return response()->json([
            'message' => 'Cập nhật danh mục thành công.',
            'data' => $category,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $category = Category::findOrFail($id);

        $this->imageService->delete($category->image);

        $category->delete();
        Cache::forget('categories_active');

        return response()->json([
            'message' => 'Xóa danh mục thành công.',
        ]);
    }

    public function destroyMultiple(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'exists:categories,id'],
        ]);

        $categories = Category::whereIn('id', $request->ids)->get();
        foreach ($categories as $category) {
            $this->imageService->delete($category->image);
        }

        $count = Category::whereIn('id', $request->ids)->delete();
        Cache::forget('categories_active');

        return response()->json([
            'message' => "Đã xóa {$count} danh mục thành công.",
        ]);
    }

    public function all(): JsonResponse
    {
        $categories = Cache::rememberForever('categories_active', function () {
            return Category::where('is_active', 1)
                ->orderBy('sort_order', 'asc')
                ->orderBy('name', 'asc')
                ->get();
        });

        return response()->json([
            'data' => $categories,
        ]);
    }
}
