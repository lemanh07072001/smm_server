<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\ProviderService;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    public function addOrder(Request $request): JsonResponse
    {
        // Validate request
        $validated = $request->validate([
            'service_id' => ['required', 'integer', 'exists:services,id'],
            'provider_service_id' => ['required', 'integer', 'exists:provider_services,id'],
            'link' => ['required', 'string', 'max:1000'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reactions' => ['nullable', 'array'],
        ]);

        // Lấy user từ authenticated request
        $user = $request->user();
        
        // Lấy Service với ProviderService và Provider (nested relationship)
        $service = Service::with(['providerService.provider'])
            ->where('provider_service_id', $validated['provider_service_id'])
            ->first();

        if (!$service) {
            return response()->json([
                'message' => 'Service không tồn tại.',
            ], 404);
        }

        // Lấy provider thông qua providerService
        $provider = $service->providerService->provider;
        
        if (!$provider) {
            return response()->json([
                'message' => 'Provider không tồn tại.',
            ], 404);
        }

        $providerApi = null;
        $body = [];

        switch($provider->code){
            case 'trao_doi_tuong_tac':
                // Xử lý URL để tránh double slash
                $baseUrl = rtrim($provider->api_url, '/');
                $providerApi = $baseUrl . '/api/v3';

                $body = [
                    'key'       => $provider->api_key,
                    'action'    => 'add',
                    'service'   => $service->providerService->provider_service_code,
                    'link'      => $validated['link'],
                    'quantity'  => $validated['quantity'],
                ];

               if (!$providerApi || empty($body)) {
                  return response()->json([
                      'message' => 'Provider không được hỗ trợ.',
                  ], 400);
               }

              // Tính toán số tiền cần trừ trước khi gọi API
              $costRate = $service->providerService->cost_rate;
              $sellRate = $service->sell_rate;
              $quantity = $validated['quantity'];
              
              $costAmount = $costRate * $quantity;
              $chargeAmount = $sellRate * $quantity;
              $profitAmount = $chargeAmount - $costAmount;

              // Kiểm tra số dư của user
              $user->refresh(); // Refresh để lấy balance mới nhất
              if ($user->balance < $chargeAmount) {
                  return response()->json([
                      'message' => 'Số dư không đủ để thực hiện đơn hàng.',
                      'balance' => (float) $user->balance,
                      'required' => (float) $chargeAmount,
                      'shortage' => (float) ($chargeAmount - $user->balance),
                  ], 400);
              }

              try {
                  // Debug: Log URL và body trước khi gọi
                  Log::info('Calling Provider API', [
                      'url' => $providerApi,
                      'api_url_from_db' => $provider->api_url,
                      'body' => $body,
                  ]);

 
                  // Gọi API đến provider với JSON format
                  $response = Http::when($provider->api_key, function ($http) use ($provider) {
                      return $http->withToken($provider->api_key);
                  })
                  ->post($providerApi, $body);
      
         
                  $statusCode = $response->status();
                  $responseBody = $response->body();
                  $responseData = $response->json();

                 
                  // Kiểm tra response thành công
                  if ($response->successful()) {
                      // Sử dụng transaction để đảm bảo tính nhất quán
                      DB::beginTransaction();
                      try {
                          // Trừ tiền từ balance của user
                          $user->balance -= $chargeAmount;
                          $user->save();

                          // Tạo order trong database
                          $order = Order::create([
                          'user_id' => $user->id, // ID người dùng đặt hàng
                          'service_id' => $validated['service_id'], // ID dịch vụ trong hệ thống
                          'provider_service_id' => $validated['provider_service_id'], // ID dịch vụ từ provider (denormalize)
                          'provider_order_id' => $responseData['order'] ?? $responseData['id'] ?? null, // Order ID từ provider API, NULL khi chưa đẩy
                          'link' => $validated['link'], // Link khách nhập
                          'quantity' => $quantity, // Số lượng đặt
                          'status' => Order::STATUS_PENDING, // Trạng thái: pending, processing, in_progress, completed, partial, canceled, refunded, failed
                          'cost_rate' => $costRate, // Snapshot giá mua lúc đặt (từ provider_service)
                          'sell_rate' => $sellRate, // Snapshot giá bán lúc đặt (từ service)
                          'charge_amount' => $chargeAmount, // Số tiền trừ user (sell_rate * quantity)
                          'cost_amount' => $costAmount, // Số tiền cost (cost_rate * quantity)
                          'profit_amount' => $profitAmount, // Lợi nhuận dự kiến (charge_amount - cost_amount)
                          'refund_amount' => 0, // Số tiền hoàn (nếu partial/cancel)
                          'final_charge' => 0, // charge - refund (sẽ cập nhật khi order kết thúc)
                          'final_cost' => 0, // cost thực tế (sẽ cập nhật khi order kết thúc)
                          'final_profit' => 0, // profit thực tế (sẽ cập nhật khi order kết thúc)
                          'is_finalized' => false, // Default 0, = 1 khi order kết thúc
                      ]);

                          $order->load(['user', 'service', 'providerService']);

                          // Commit transaction
                          DB::commit();

                          return response()->json([
                              'message' => 'Tạo đơn hàng thành công.',
                              'data' => [
                                  'order' => $order,
                                  'provider_response' => $responseData,
                                  'new_balance' => (float) $user->balance,
                              ],
                          ], 201);
                      } catch (\Exception $e) {
                          // Rollback nếu có lỗi khi tạo order
                          DB::rollBack();
                          
                          Log::error('Error creating order after provider success', [
                              'error' => $e->getMessage(),
                              'user_id' => $user->id,
                              'charge_amount' => $chargeAmount,
                          ]);

                          return response()->json([
                              'message' => 'Lỗi khi tạo đơn hàng. Vui lòng thử lại.',
                              'error' => $e->getMessage(),
                          ], 500);
                      }
                  } else {
                      // API trả về lỗi - không cần hoàn tiền vì chưa trừ
                      return response()->json([
                          'message' => 'Lỗi khi gọi API provider.',
                          'error' => $responseData ?: $responseBody,
                          'status_code' => $statusCode,
                      ], 400);
                  }
                
              } catch (\Exception $e) {
                  // Xử lý exception (timeout, connection error, etc.)
                  Log::error('Provider API Error', [
                      'provider' => $provider->code,
                      'url' => $providerApi,
                      'error' => $e->getMessage(),
                  ]);
      
                  return response()->json([
                      'message' => 'Lỗi kết nối đến provider.',
                      'error' => $e->getMessage(),
                  ], 500);
              }
                break;
        }

    
    }
}

