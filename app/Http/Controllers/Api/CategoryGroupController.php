<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryGroupRequest;
use App\Http\Requests\UpdateCategoryGroupRequest;
use App\Models\CategoryGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CategoryGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 10);
        $page = $request->input('page', 1);
        $search = $request->input('search');
        $status = $request->input('is_active');

        $query = CategoryGroup::with(['services'])
            ->orderBy('sort_order', 'asc')
            ->orderBy('created_at', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($status !== null) {
            $query->where('is_active', $status === '1' || $status === 'true' ? 1 : 0);
        }

        $total = $query->count();
        $totalPages = (int) ceil($total / $limit);

        $categoryGroups = $query->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return response()->json([
            'data' => $categoryGroups,
            'total' => $total,
            'page' => (int) $page,
            'limit' => (int) $limit,
            'totalPages' => $totalPages,
        ]);
    }

    public function store(StoreCategoryGroupRequest $request): JsonResponse
    {
        $data = $request->validated();

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('category-groups', 'public');
            $data['image'] = $path;
        }

        $categoryGroup = CategoryGroup::create($data);

        return response()->json([
            'message' => 'Tạo nhóm danh mục thành công.',
            'data' => $categoryGroup,
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $categoryGroup = CategoryGroup::findOrFail($id);

        return response()->json([
            'data' => $categoryGroup,
        ]);
    }

    public function update(UpdateCategoryGroupRequest $request, string $id): JsonResponse
    {
        $categoryGroup = CategoryGroup::findOrFail($id);
        $data = $request->validated();

        if ($request->hasFile('image')) {
            // Xóa ảnh cũ nếu có
            if ($categoryGroup->image) {
                Storage::disk('public')->delete($categoryGroup->image);
            }
            $path = $request->file('image')->store('category-groups', 'public');
            $data['image'] = $path;
        }

        $categoryGroup->update($data);

        return response()->json([
            'message' => 'Cập nhật nhóm danh mục thành công.',
            'data' => $categoryGroup,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $categoryGroup = CategoryGroup::findOrFail($id);

        // Xóa ảnh nếu có
        if ($categoryGroup->image) {
            Storage::disk('public')->delete($categoryGroup->image);
        }

        $categoryGroup->delete();

        return response()->json([
            'message' => 'Xóa nhóm danh mục thành công.',
        ]);
    }

    public function destroyMultiple(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'exists:category_groups,id'],
        ]);

        $count = CategoryGroup::whereIn('id', $request->ids)->delete();

        return response()->json([
            'message' => "Đã xóa {$count} nhóm danh mục thành công.",
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $query = CategoryGroup::where('is_active', 1)
            ->orderBy('sort_order', 'asc')
            ->orderBy('name', 'asc')
            ->with(['services' => function ($q) {
                $q->where('is_active', 1)
                    ->orderBy('sort_order', 'asc')
                    ->orderBy('name', 'asc')
                    ->with('providerService');
            }]);

        return response()->json([
            'data' => $query->get(),
        ]);
    }
}
