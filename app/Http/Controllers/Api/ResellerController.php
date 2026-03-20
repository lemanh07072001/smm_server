<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PartnerProvider;
use App\Models\Provider;
use App\Models\ResellerProviderPermission;
use App\Models\Service;
use App\Models\User;
use App\Services\OrderCreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ResellerController extends Controller
{
    public function __construct(
        protected OrderCreationService $orderService
    ) {}

    /**
     * Lấy danh sách services mà reseller được phép dùng (provider đã được cấp phép).
     */
    public function services(Request $request): JsonResponse
    {
        $user = $request->user();

        $allowedProviderIds = ResellerProviderPermission::where('user_id', $user->id)
            ->where('is_allowed', true)
            ->pluck('provider_id');

        $services = Service::with(['providerService.provider', 'category'])
            ->where('is_active', 1)
            ->whereHas('providerService.provider', function ($q) use ($allowedProviderIds) {
                $q->whereIn('id', $allowedProviderIds)->where('is_active', true);
            })
            ->get()
            ->map(function ($service) use ($user) {
                return [
                    'id'               => $service->id,
                    'name'             => $service->name,
                    'platform'         => $service->platform,
                    'category'         => $service->category?->name,
                    'min_quantity'     => $service->min_quantity,
                    'max_quantity'     => $service->max_quantity,
                    'sell_rate'        => (float) $service->getPriceForUser($user),
                    'provider_code'    => $service->providerService->provider->code,
                    'service_status'   => $service->service_status,
                    'description'      => $service->description,
                ];
            });

        return response()->json(['data' => $services]);
    }

    /**
     * Lấy danh sách providers mà reseller được phép dùng.
     */
    public function allowedProviders(Request $request): JsonResponse
    {
        $user = $request->user();

        $permissions = ResellerProviderPermission::with('provider')
            ->where('user_id', $user->id)
            ->where('is_allowed', true)
            ->get();

        $providers = $permissions->map(fn($p) => [
            'id'        => $p->provider->id,
            'code'      => $p->provider->code,
            'name'      => $p->provider->name,
            'is_active' => $p->provider->is_active,
        ]);

        return response()->json(['data' => $providers]);
    }

    /**
     * Kiểm tra provider có được phép không (site đại lý gọi khi thêm provider mới).
     */
    public function checkProvider(Request $request): JsonResponse
    {
        $request->validate([
            'provider_code' => ['required', 'string'],
        ]);

        $user = $request->user();
        $provider = Provider::where('code', $request->provider_code)->first();

        if (!$provider) {
            return response()->json([
                'allowed' => false,
                'message' => 'Nhà cung cấp không tồn tại trên hệ thống.',
            ], 404);
        }

        // Kiểm tra ở cấp hệ thống (superadmin có cho phép provider này không)
        $partnerProvider = PartnerProvider::where('code', $provider->code)->first();
        if (!$partnerProvider || !$partnerProvider->is_allowed) {
            return response()->json([
                'allowed' => false,
                'message' => 'Nhà cung cấp này không được hỗ trợ trên hệ thống. Vui lòng liên hệ admin.',
            ], 403);
        }

        if (!$provider->is_active) {
            return response()->json([
                'allowed' => false,
                'message' => 'Nhà cung cấp hiện không hoạt động.',
            ], 422);
        }

        $hasPermission = $user->canUseProvider($provider->id);

        if (!$hasPermission) {
            return response()->json([
                'allowed' => false,
                'message' => 'Nhà cung cấp này không được hỗ trợ, vui lòng liên hệ admin.',
            ], 403);
        }

        return response()->json([
            'allowed'  => true,
            'provider' => [
                'id'   => $provider->id,
                'code' => $provider->code,
                'name' => $provider->name,
            ],
        ]);
    }

    /**
     * Đặt hàng từ site đại lý.
     * Không cần check provider permission ở đây vì đã check khi thêm provider (checkProvider).
     */
    public function addOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'service_id'          => ['required', 'integer', 'exists:services,id'],
            'provider_service_id' => ['nullable', 'integer', 'exists:provider_services,id'],
            'link'                => ['required', 'string', 'max:50000'],
            'quantity'            => ['required', 'integer', 'min:1', 'max:10000000'],
            'comments'            => ['nullable', 'string', 'max:5000'],
            'livestream_duration' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        $user = $request->user();

        // Validate service
        $result = $this->orderService->validateService(
            $validated['service_id'],
            $validated['provider_service_id'] ?? null
        );
        if (isset($result['error'])) {
            return response()->json(['message' => $result['error']], $result['code']);
        }
        $service = $result['service'];

        // Kiểm tra ở cấp hệ thống: PartnerProvider có is_allowed không
        $provider = $service->providerService->provider;
        $partnerAllowed = \Illuminate\Support\Facades\Cache::remember(
            "partner_provider_allowed_{$provider->code}", 300,
            fn() => PartnerProvider::where('code', $provider->code)->value('is_allowed')
        );

        if (!$partnerAllowed) {
            return response()->json([
                'message' => "Nhà cung cấp [{$provider->name}] không được hỗ trợ trên hệ thống. Vui lòng liên hệ admin.",
            ], 403);
        }

        // Kiểm tra permission cấp user (cache 10 phút)
        $cacheKey = "reseller_permission_{$user->id}_{$provider->id}";
        $allowed = \Illuminate\Support\Facades\Cache::remember($cacheKey, 600, function () use ($user, $provider) {
            return $user->canUseProvider($provider->id);
        });

        if (!$allowed) {
            return response()->json([
                'message' => "Bạn không có quyền dùng nhà cung cấp [{$provider->name}]. Vui lòng liên hệ admin.",
            ], 403);
        }

        // Validate quantity
        $qtyError = $this->orderService->validateQuantity($service, $validated['quantity']);
        if ($qtyError) {
            return response()->json(['message' => $qtyError['error']], $qtyError['code']);
        }

        $livestreamDuration = $validated['livestream_duration'] ?? 0;
        if ($service->platform === 'fb_view_livestream' && empty($livestreamDuration)) {
            return response()->json(['message' => 'Vui lòng nhập thời gian livestream.'], 422);
        }

        $amounts = $this->orderService->calculateAmounts($service, $user, $validated['quantity'], $livestreamDuration);

        $links = array_values(array_filter(
            array_map('trim', preg_split('/\\\\n|\r\n|\r|\n/', $validated['link'])),
            fn($l) => $l !== ''
        ));

        if (count($links) > 1) {
            return $this->addMultipleOrders($user, $service, $links, $validated, $amounts, $livestreamDuration);
        }

        $validated['link'] = $links[0] ?? $validated['link'];

        DB::beginTransaction();
        try {
            $user = User::where('id', $user->id)->lockForUpdate()->first();

            if ($user->balance < $amounts['chargeAmount']) {
                DB::rollBack();
                return response()->json([
                    'message'  => 'Số dư không đủ để thực hiện đơn hàng.',
                    'balance'  => (float) $user->balance,
                    'required' => (float) $amounts['chargeAmount'],
                    'shortage' => (float) ($amounts['chargeAmount'] - $user->balance),
                ], 400);
            }

            $order = $this->orderService->createOrderRecord(
                $user, $service, $validated['link'], $validated['quantity'],
                $amounts, 'reseller', $livestreamDuration,
                $validated['comments'] ?? null,
                '[Reseller API] user_id=' . $user->id
            );

            DB::commit();

            $this->orderService->postCreateOrder($order, $user, $service, $amounts, $validated['quantity']);

            return response()->json([
                'message' => 'Tạo đơn hàng thành công.',
                'data'    => [
                    'order_id'    => $order->id,
                    'status'      => $order->status,
                    'charge'      => (float) $amounts['chargeAmount'],
                    'new_balance' => (float) $user->balance,
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ResellerController: error creating order', [
                'error'   => $e->getMessage(),
                'user_id' => $user->id,
            ]);
            return response()->json(['message' => 'Lỗi khi tạo đơn hàng. Vui lòng thử lại.'], 500);
        }
    }

    /**
     * Tạo nhiều đơn hàng (nhiều link) cho reseller.
     */
    private function addMultipleOrders(
        User $user, $service, array $links, array $validated, array $amounts, int $livestreamDuration
    ): JsonResponse {
        $totalCharge = $amounts['chargeAmount'] * count($links);

        DB::beginTransaction();
        try {
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();

            if ($lockedUser->balance < $totalCharge) {
                DB::rollBack();
                return response()->json([
                    'message'  => 'Số dư không đủ để thực hiện tất cả đơn hàng.',
                    'balance'  => (float) $lockedUser->balance,
                    'required' => (float) $totalCharge,
                    'shortage' => (float) ($totalCharge - $lockedUser->balance),
                ], 400);
            }

            $orders = [];
            foreach ($links as $link) {
                $order = $this->orderService->createOrderRecord(
                    $lockedUser, $service, $link, $validated['quantity'],
                    $amounts, 'reseller', $livestreamDuration,
                    $validated['comments'] ?? null,
                    '[Reseller API] user_id=' . $lockedUser->id
                );
                $orders[] = $order;
                $lockedUser->refresh();
            }

            DB::commit();

            foreach ($orders as $order) {
                $this->orderService->postCreateOrder($order, $lockedUser, $service, $amounts, $validated['quantity']);
            }

            return response()->json([
                'message' => 'Tạo ' . count($orders) . ' đơn hàng thành công.',
                'data'    => [
                    'order_ids'   => array_column($orders, 'id'),
                    'total_charge' => (float) $totalCharge,
                    'new_balance' => (float) $lockedUser->fresh()->balance,
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ResellerController: error creating multiple orders', [
                'error'   => $e->getMessage(),
                'user_id' => $user->id,
            ]);
            return response()->json(['message' => 'Lỗi khi tạo đơn hàng. Vui lòng thử lại.'], 500);
        }
    }

    /**
     * Lấy danh sách đơn hàng của reseller.
     */
    public function orders(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = \App\Models\Order::with(['service'])
            ->where('user_id', $user->id)
            ->where('order_source', 'reseller')
            ->orderBy('created_at', 'desc');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->whereHas('service', fn($s) => $s->where('name', 'like', "%$search%"))
                  ->orWhere('link', 'like', "%$search%")
                  ->orWhere('id', is_numeric($search) ? (int) $search : -1);
            });
        }

        $orders = $query->paginate($request->input('per_page', 20));

        return response()->json($orders);
    }

    /**
     * Lấy số dư hiện tại của reseller.
     */
    public function balance(Request $request): JsonResponse
    {
        $user = $request->user();
        return response()->json([
            'balance' => (float) $user->balance,
        ]);
    }
}
