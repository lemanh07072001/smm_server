<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreServiceRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Models\CategoryGroup;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ServiceController extends Controller
{
    /**
     * Clear all services cache
     */
    private function clearServicesCache(): void
    {
        // Clear all cache keys that start with 'services_group_'
        $cacheKeys = Cache::get('services_cache_keys', []);
        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }
        Cache::forget('services_cache_keys');
    }

    /**
     * Store cache key for tracking
     */
    private function storeCacheKey(string $key): void
    {
        $cacheKeys = Cache::get('services_cache_keys', []);
        if (!in_array($key, $cacheKeys)) {
            $cacheKeys[] = $key;
            Cache::put('services_cache_keys', $cacheKeys, 3600);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 10);
        $page = $request->input('page', 1);
        $search = $request->input('search');
        $status = $request->input('is_active');
        $categoryGroupId = $request->input('category_group_id');
        $providerServiceId = $request->input('provider_service_id');

        $query = Service::with(['providerService.provider'])
            ->orderBy('sort_order', 'asc')
            ->orderBy('created_at', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($status !== null) {
            $query->where('is_active', $status === '1' || $status === 'true' ? 1 : 0);
        }

        if ($categoryGroupId !== null) {
            $query->where('category_group_id', $categoryGroupId);
        }

        if ($providerServiceId !== null) {
            $query->where('provider_service_id', $providerServiceId);
        }

        $total = $query->count();
        $totalPages = (int) ceil($total / $limit);

        $services = $query->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return response()->json([
            'data' => $services,
            'total' => $total,
            'page' => (int) $page,
            'limit' => (int) $limit,
            'totalPages' => $totalPages,
        ]);
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $data = $request->validated();

        $service = Service::create($data);
        $service->load(['categoryGroup', 'providerService']);

        // Clear cache after creating new service
        $this->clearServicesCache();

        return response()->json([
            'message' => 'Tạo dịch vụ thành công.',
            'data' => $service,
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        $service = Service::with(['categoryGroup', 'providerService'])->findOrFail($id);

        return response()->json([
            'data' => $service,
        ]);
    }

    public function update(UpdateServiceRequest $request, string $id): JsonResponse
    {
        $service = Service::findOrFail($id);
        $data = $request->validated();

        Log::info('Update service data', [
            'id' => $id,
            'validated' => $data,
            'raw_agent_price' => $request->input('agent_price'),
        ]);

        $service->update($data);
        $service->load(['categoryGroup', 'providerService']);

        // Clear cache after updating service
        $this->clearServicesCache();

        return response()->json([
            'message' => 'Cập nhật dịch vụ thành công.',
            'data' => $service,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $service = Service::findOrFail($id);
        $service->delete();

        // Clear cache after deleting service
        $this->clearServicesCache();

        return response()->json([
            'message' => 'Xóa dịch vụ thành công.',
        ]);
    }

    public function destroyMultiple(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'exists:services,id'],
        ]);

        $count = Service::whereIn('id', $request->ids)->delete();

        // Clear cache after deleting multiple services
        $this->clearServicesCache();

        return response()->json([
            'message' => "Đã xóa {$count} dịch vụ thành công.",
        ]);
    }

    public function platforms(): JsonResponse
    {
        return response()->json([
            'data' => Service::PLATFORM,
        ]);
    }

    public function all(): JsonResponse
    {
        $categoryGroups = CategoryGroup::where('is_active', 1)
            ->orderBy('sort_order', 'asc')
            ->orderBy('name', 'asc')
            // ->with(['services' => function ($query) {
            //     $query->where('is_active', 1)
            //         ->with(['categoryGroup', 'providerService'])
            //         ->orderBy('sort_order', 'asc')
            //         ->orderBy('name', 'asc');
            // }])
            ->get();

        return response()->json([
            'data' => $categoryGroups,
        ]);
    }

    public function formTypes(): JsonResponse
    {
        $data = Cache::remember('service_form_types', 3600, function () {
            return [
                'feel_form' => Service::FEEL_FORM,
                'comment_form' => Service::COMMENT_FORM,
            ];
        });

        return response()->json([
            'data' => $data,
        ]);
    }

    public function getByGroupId(Request $request, ?string $groupId = null): JsonResponse
    {
        // Get filter parameters
        $isActive = $request->input('is_active');
        $categoryGroupId = $request->input('category_group_id');

        // Create cache key based on parameters
        $cacheKey = 'services_group_' .
                    ($groupId ?? 'all') . '_' .
                    ($isActive ?? 'any') . '_' .
                    ($categoryGroupId ?? 'any');

        // Store cache key for tracking
        $this->storeCacheKey($cacheKey);

        // Cache for 1 hour (3600 seconds)
        $data = Cache::remember($cacheKey, 3600, function () use ($groupId, $isActive, $categoryGroupId) {
            $query = Service::with(['categoryGroup', 'providerService', 'country'])
                ->orderBy('sort_order', 'asc')
                ->orderBy('name', 'asc');

            // Filter by group_id if provided
            if ($groupId !== null && $groupId !== 'all') {
                $query->where('group_id', $groupId);
            }

            // Filter by is_active if provided
            if ($isActive !== null) {
                $query->where('is_active', $isActive === 1 || $isActive === 'true' ? 1 : 0);
            }

            // Filter by category_group_id if provided
            if ($categoryGroupId !== null) {
                $query->where('category_group_id', $categoryGroupId);
            }

            $services = $query->get();

            return [
                'data' => $services,
                'total' => $services->count(),
                'group_id' => $groupId,
            ];
        });

        return response()->json($data);
    }
}
