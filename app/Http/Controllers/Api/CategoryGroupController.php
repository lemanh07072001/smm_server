<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCategoryGroupRequest;
use App\Http\Requests\UpdateCategoryGroupRequest;
use App\Models\CategoryGroup;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
        $this->clearCache();

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
        $this->clearCache();

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
        $this->clearCache();

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
        $this->clearCache();

        return response()->json([
            'message' => "Đã xóa {$count} nhóm danh mục thành công.",
        ]);
    }

    public function all(Request $request): JsonResponse
    {
        $data = Cache::remember('category_groups_all', 3600, function () {
            return CategoryGroup::where('is_active', 1)
                ->orderBy('sort_order', 'asc')
                ->orderBy('name', 'asc')
                ->with(['services' => function ($q) {
                    $q->where('is_active', 1)
                        ->orderBy('sort_order', 'asc')
                        ->orderBy('name', 'asc')
                        ->with('providerService');
                }])
                ->get();
        });

        return response()->json(['data' => $data]);
    }

    public function getAll(Request $request): JsonResponse
    {
        $data = Cache::remember('category_groups_all', 3600, function () {
            return CategoryGroup::where('is_active', 1)
                ->orderBy('sort_order', 'asc')
                ->orderBy('name', 'asc')
                ->with(['services' => function ($q) {
                    $q->where('is_active', 1)
                        ->orderBy('sort_order', 'asc')
                        ->orderBy('name', 'asc')
                        ->with('providerService');
                }])
                ->get();
        });

        $data = $this->applyRolePricing($data, $request->user('sanctum'));

        return response()->json(['data' => $data]);
    }

    private function applyRolePricing($categoryGroups, ?User $user)
    {
        $isReseller = $user && $user->role === User::ROLE_RESELLER;
        $isAdmin = $user && in_array($user->role, [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN, User::ROLE_TAX], true);

        if ($isAdmin) {
            return $categoryGroups;
        }

        return $categoryGroups->map(function ($group) use ($isReseller) {
            $clone = clone $group;
            if ($clone->relationLoaded('services')) {
                $clone->setRelation('services', $clone->services->map(function ($service) use ($isReseller) {
                    $svc = clone $service;
                    if ($isReseller && $svc->agent_price !== null) {
                        $svc->sell_rate = $svc->agent_price;
                    }
                    $svc->agent_price = null;
                    return $svc;
                }));
            }
            return $clone;
        });
    }

    public function list(): JsonResponse
    {
        $data = Cache::remember('category_groups_list', 3600, function () {
            return CategoryGroup::where('is_active', 1)
                ->orderBy('sort_order', 'asc')
                ->orderBy('name', 'asc')
                ->get(['id', 'name', 'slug', 'image', 'is_active'])
                ->map(fn($item) => [
                    'id'        => $item->id,
                    'name'      => $item->name,
                    'slug'      => $item->slug,
                    'image'     => $item->image_url,
                    'is_active' => $item->is_active,
                ]);
        });

        return response()->json(['data' => $data]);
    }

    private function clearCache(): void
    {
        Cache::forget('category_groups_all');
        Cache::forget('category_groups_list');
    }
}
