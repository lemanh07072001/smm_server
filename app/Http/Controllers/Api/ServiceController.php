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

class ServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 10);
        $page = $request->input('page', 1);
        $search = $request->input('search');
        $status = $request->input('is_active');
        $categoryGroupId = $request->input('category_group_id');
        $providerServiceId = $request->input('provider_service_id');

        $query = Service::with(['categoryGroup', 'providerService'])
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

        $service->update($data);
        $service->load(['categoryGroup', 'providerService']);

        return response()->json([
            'message' => 'Cập nhật dịch vụ thành công.',
            'data' => $service,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $service = Service::findOrFail($id);
        $service->delete();

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
            ->with(['services' => function ($query) {
                $query->where('is_active', 1)
                    ->with(['categoryGroup', 'providerService'])
                    ->orderBy('sort_order', 'asc')
                    ->orderBy('name', 'asc');
            }])
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
}
