<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProviderServiceRequest;
use App\Http\Requests\UpdateProviderServiceRequest;
use App\Models\ProviderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderServiceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 10);
        $page = $request->input('page', 1);
        $search = $request->input('search');
        $status = $request->input('is_active');
        $providerId = $request->input('provider_id');

        $query = ProviderService::with('provider')->orderBy('created_at', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('provider_service_code', 'like', "%{$search}%")
                  ->orWhere('category_name', 'like', "%{$search}%");
            });
        }

        if ($status !== null) {
            $query->where('is_active', $status === '1' || $status === 'true' ? 1 : 0);
        }

        if ($providerId !== null) {
            $query->where('provider_id', $providerId);
        }

        $total = $query->count();
        $totalPages = (int) ceil($total / $limit);

        $providerServices = $query->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return response()->json([
            'data' => $providerServices,
            'total' => $total,
            'page' => (int) $page,
            'limit' => (int) $limit,
            'totalPages' => $totalPages,
        ]);
    }

    public function store(StoreProviderServiceRequest $request): JsonResponse
    {
        $data = $request->validated();


        $providerService = ProviderService::create($data);
        $providerService->load('provider');

        return response()->json([
            'message' => 'Tạo dịch vụ provider thành công.',
            'data' => $providerService,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $providerService = ProviderService::with('provider')->findOrFail($id);

        return response()->json([
            'data' => $providerService,
        ]);
    }

    public function update(UpdateProviderServiceRequest $request, int $id): JsonResponse
    {
        $providerService = ProviderService::findOrFail($id);
        $data = $request->validated();

        logger($request->all());
        // Nếu request có gửi reaction_types (kể cả null), thì set giá trị đó
        if ($request->has('reaction_types')) {
            $data['reaction_types'] = $request->input('reaction_types');
        }

        $providerService->update($data);
        $providerService->load('provider');

        return response()->json([
            'message' => 'Cập nhật dịch vụ provider thành công.',
            'data' => $providerService,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $providerService = ProviderService::findOrFail($id);
        $providerService->delete();

        return response()->json([
            'message' => 'Xóa dịch vụ provider thành công.',
        ]);
    }

    public function destroyMultiple(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'exists:provider_services,id'],
        ]);

        $count = ProviderService::whereIn('id', $request->ids)->delete();

        return response()->json([
            'message' => "Đã xóa {$count} dịch vụ provider thành công.",
        ]);
    }

    public function all(): JsonResponse
    {
        $providerServices = ProviderService::with(['provider', 'services'])
            ->where('is_active', 1)
            ->orderBy('name', 'asc')
            ->get();

        return response()->json([
            'data' => $providerServices,
        ]);
    }
}
