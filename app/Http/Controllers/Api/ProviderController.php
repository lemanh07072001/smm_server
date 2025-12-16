<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProviderRequest;
use App\Http\Requests\UpdateProviderRequest;
use App\Models\Provider;
use App\Services\ImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderController extends Controller
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

        $query = Provider::orderBy('created_at', 'desc');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($status !== null) {
            $query->where('is_active', $status === '1' || $status === 'true' ? 1 : 0);
        }

        $total = $query->count();
        $totalPages = (int) ceil($total / $limit);

        $providers = $query->skip(($page - 1) * $limit)
            ->take($limit)
            ->get();

        return response()->json([
            'data' => $providers,
            'total' => $total,
            'page' => (int) $page,
            'limit' => (int) $limit,
            'totalPages' => $totalPages,
        ]);
    }

    public function store(StoreProviderRequest $request): JsonResponse
    {
        $data = $request->validated();

        $provider = Provider::create($data);

        return response()->json([
            'message' => 'Tạo provider thành công.',
            'data' => $provider,
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $provider = Provider::findOrFail($id);
        $provider->makeVisible('api_key');

        return response()->json([
            'data' => $provider,
        ]);
    }

    public function update(UpdateProviderRequest $request, int $id): JsonResponse
    {
        $provider = Provider::findOrFail($id);
        $data = $request->validated();

        // Don't update api_key if not provided
        if (empty($data['api_key'])) {
            unset($data['api_key']);
        }

        $provider->update($data);
        $provider->makeVisible('api_key');

        return response()->json([
            'message' => 'Cập nhật provider thành công.',
            'data' => $provider,
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $provider = Provider::findOrFail($id);

        // Delete image
        $this->imageService->delete($provider->image);

        $provider->delete();

        return response()->json([
            'message' => 'Xóa provider thành công.',
        ]);
    }

    public function destroyMultiple(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer', 'exists:providers,id'],
        ]);

        // Delete images
        $providers = Provider::whereIn('id', $request->ids)->get();
        foreach ($providers as $provider) {
            $this->imageService->delete($provider->image);
        }

        $count = Provider::whereIn('id', $request->ids)->delete();

        return response()->json([
            'message' => "Đã xóa {$count} provider thành công.",
        ]);
    }

    public function getProvider(): JsonResponse
    {
        $providers = Provider::where('is_active', 1)
            ->orderBy('name', 'asc')
            ->get(['id', 'name', 'code']);

        return response()->json([
            'data' => $providers,
        ]);
    }
}
