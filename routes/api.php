<?php

use App\Http\Controllers\Api\AffiliateController;
use App\Http\Controllers\Api\ResellerController;
use App\Http\Controllers\Api\ResellerPermissionController;
use App\Http\Controllers\Api\ApiOrderController;
use App\Http\Controllers\Api\PartnerProviderController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankAutoController;
use App\Http\Controllers\Api\TransactionBankController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\Pay2sController;
use App\Http\Controllers\Api\CategoryGroupController;
use App\Http\Controllers\Api\CountryController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepositController;
use App\Http\Controllers\Api\DongtienController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\ProviderServiceController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\StatisticsController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Webhook routes (public - không cần auth, không giới hạn rate limit)
Route::post('/webhook/pay2s', [Pay2sController::class, 'webhook']);

// Public routes
Route::middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});
Route::middleware('throttle:5,1')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
});
Route::get('/categories/all', [CategoryController::class, 'all']);
Route::get('/category-groups/all', [CategoryGroupController::class, 'all']);
Route::get('/category-groups/get-all', [CategoryGroupController::class, 'getAll']);
Route::get('/category-groups/list', [CategoryGroupController::class, 'list']);
Route::get('/services/all', [ServiceController::class, 'all']);
Route::get('/services/form-types', [ServiceController::class, 'formTypes']);
Route::get('/services/platforms', [ServiceController::class, 'platforms']);
Route::get('/services/group/{groupId?}', [ServiceController::class, 'getByGroupId']);

Route::post('/get-providers', [ProviderController::class, 'getProvider']);
Route::get('/countries/all', [CountryController::class, 'all']);
Route::get('/settings/all', [SettingController::class, 'all']);
Route::get('/settings/public', [SettingController::class, 'publicIndex']);

// Public Statistics API
Route::get('/statistics/summary', [StatisticsController::class, 'summary']);
Route::get('/statistics/revenue', [StatisticsController::class, 'revenue']);
Route::get('/statistics/daily-breakdown', [StatisticsController::class, 'dailyBreakdown']);
Route::get('/statistics/order-completion-time', [StatisticsController::class, 'orderCompletionTime']);

// Public Notifications API
Route::get('/notifications', [NotificationController::class, 'index']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // ── Session ──────────────────────────────────────────────────────
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/profile', [AuthController::class, 'profile']);

    // ── Current user actions ────────────────────────────────────────
    Route::post('/user/generate-api-key', [UserController::class, 'generateMyApiKey']);
    Route::get('/user/api-key', [UserController::class, 'getMyApiKey']);

    // ── Orders (user creates/cancels, admin-or-self for view) ────────
    Route::post('/add-order', [OrderController::class, 'addOrder'])->middleware('throttle:60,1');
    Route::post('/orders/{orderId}/cancel', [OrderController::class, 'cancelOrder'])->middleware('throttle:30,1');
    Route::get('/orders/user/{userId}', [OrderController::class, 'getOrdersByUser']);   // internal admin-or-self check
    Route::get('/orders/{orderId}/logs', [OrderController::class, 'getOrderLogs']);     // internal admin-or-self check

    // ── Transactions & Deposits (user-scoped trong controller) ───────
    Route::get('/transactions', [DongtienController::class, 'index']);
    Route::get('/deposits', [DepositController::class, 'index']);
    Route::get('/deposits/pending/current', [DepositController::class, 'currentPending']);
    Route::post('/deposits/pending', [DepositController::class, 'createPending'])->middleware('throttle:10,1');
    Route::post('/deposits/{id}/cancel', [DepositController::class, 'cancel'])->middleware('throttle:10,1');
    Route::get('/deposits/{id}', [DepositController::class, 'show']);

    // ── Dashboard user-callable ─────────────────────────────────────
    Route::get('/dashboard/user', [DashboardController::class, 'userStats']);
    Route::get('/dashboard/recent-logins', [DashboardController::class, 'recentLogins']);
    Route::get('/dashboard/purchased-services', [DashboardController::class, 'userPurchasedServices']);
    Route::get('/dashboard/purchased-categories', [DashboardController::class, 'userPurchasedCategories']);

    // ── Affiliate user-callable ─────────────────────────────────────
    Route::get('/affiliate/dashboard', [AffiliateController::class, 'dashboard']);
    Route::get('/affiliate/commissions', [AffiliateController::class, 'commissions']);
    Route::get('/affiliate/referrals', [AffiliateController::class, 'referrals']);
    Route::get('/affiliate/link', [AffiliateController::class, 'getLink']);
    Route::post('/affiliate/withdraw', [AffiliateController::class, 'withdraw'])->middleware('throttle:5,1');
    Route::get('/affiliate/withdrawals', [AffiliateController::class, 'withdrawals']);

    // ── Admin-only routes (yêu cầu role admin/super_admin) ──────────
    Route::middleware('admin')->group(function () {
        // Categories CRUD
        Route::get('/categories', [CategoryController::class, 'index']);
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::post('/categories/delete-multiple', [CategoryController::class, 'destroyMultiple']);
        Route::get('/categories/{id}', [CategoryController::class, 'show']);
        Route::post('/categories/{id}', [CategoryController::class, 'update']);
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

        // Category Groups CRUD
        Route::get('/category-groups', [CategoryGroupController::class, 'index']);
        Route::post('/category-groups', [CategoryGroupController::class, 'store']);
        Route::post('/category-groups/delete-multiple', [CategoryGroupController::class, 'destroyMultiple']);
        Route::get('/category-groups/all', [CategoryGroupController::class, 'all']);
        Route::get('/category-groups/{id}', [CategoryGroupController::class, 'show']);
        Route::post('/category-groups/{id}', [CategoryGroupController::class, 'update']);
        Route::delete('/category-groups/{id}', [CategoryGroupController::class, 'destroy']);

        // Partner Providers (admin view khi thêm provider)
        Route::get('/partner-providers/allowed', [PartnerProviderController::class, 'allowed']);

        // Providers CRUD
        Route::get('/providers', [ProviderController::class, 'index']);
        Route::post('/providers', [ProviderController::class, 'store']);
        Route::post('/providers/delete-multiple', [ProviderController::class, 'destroyMultiple']);
        Route::get('/providers/{id}', [ProviderController::class, 'show']);
        Route::post('/providers/{id}', [ProviderController::class, 'update']);
        Route::delete('/providers/{id}', [ProviderController::class, 'destroy']);

        // Provider Services CRUD
        Route::get('/provider-services', [ProviderServiceController::class, 'index']);
        Route::post('/provider-services', [ProviderServiceController::class, 'store']);
        Route::post('/provider-services/delete-multiple', [ProviderServiceController::class, 'destroyMultiple']);
        Route::get('/provider-services/all', [ProviderServiceController::class, 'all']);
        Route::get('/provider-services/{id}', [ProviderServiceController::class, 'show']);
        Route::post('/provider-services/{id}', [ProviderServiceController::class, 'update']);
        Route::delete('/provider-services/{id}', [ProviderServiceController::class, 'destroy']);

        // Services CRUD (public read endpoints stay outside auth group)
        Route::get('/services', [ServiceController::class, 'index']);
        Route::post('/services', [ServiceController::class, 'store']);
        Route::post('/services/delete-multiple', [ServiceController::class, 'destroyMultiple']);
        Route::get('/services/{id}', [ServiceController::class, 'show']);
        Route::post('/services/{id}', [ServiceController::class, 'update']);
        Route::delete('/services/{id}', [ServiceController::class, 'destroy']);

        // Countries (paginated admin list; public /countries/all vẫn open)
        Route::get('/countries', [CountryController::class, 'index']);

        // Users CRUD
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::post('/users/delete-multiple', [UserController::class, 'destroyMultiple']);
        Route::get('/users/{id}', [UserController::class, 'show']);
        Route::post('/users/{id}', [UserController::class, 'update']);
        Route::delete('/users/{id}', [UserController::class, 'destroy']);
        Route::post('/users/{id}/reset-password', [UserController::class, 'resetPassword']);
        Route::post('/users/{id}/generate-api-key', [UserController::class, 'generateApiKey']);
        Route::post('/users/{id}/adjust-balance', [UserController::class, 'adjustBalance']);

        // Orders (admin list)
        Route::get('/orders', [OrderController::class, 'index']);

        // Settings CRUD (public read /settings/all & /settings/public vẫn open)
        Route::get('/settings', [SettingController::class, 'index']);
        Route::post('/settings/save', [SettingController::class, 'save']);
        Route::post('/settings', [SettingController::class, 'update']);
        Route::get('/settings/{key}', [SettingController::class, 'show']);
        Route::delete('/settings/{key}', [SettingController::class, 'destroy']);

        // Upload
        Route::post('/upload/image', [UploadController::class, 'uploadImage']);
        Route::post('/upload/images', [UploadController::class, 'uploadMultipleImages']);
        Route::delete('/upload/image/{filename}', [UploadController::class, 'deleteImage']);

        // Dashboard system-wide
        Route::get('/dashboard/overview', [DashboardController::class, 'overview']);
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/dashboard/today', [DashboardController::class, 'today']);
        Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
        Route::get('/dashboard/total-stats', [DashboardController::class, 'totalStats']);
        Route::get('/dashboard/financial-stats', [DashboardController::class, 'financialStats']);
        Route::get('/dashboard/order-stats', [DashboardController::class, 'orderStats']);
        Route::get('/dashboard/revenue-chart', [DashboardController::class, 'revenueChart']);
        Route::get('/dashboard/orders-chart', [DashboardController::class, 'ordersChart']);

        // Notifications (Admin)
        Route::get('/admin/notifications', [NotificationController::class, 'adminIndex']);
        Route::post('/admin/notifications', [NotificationController::class, 'store']);
        Route::put('/admin/notifications/{id}', [NotificationController::class, 'update']);
        Route::post('/admin/notifications/{id}/status', [NotificationController::class, 'updateStatus']);
        Route::delete('/admin/notifications/{id}', [NotificationController::class, 'adminDestroy']);
        Route::post('/admin/notifications/delete-multiple', [NotificationController::class, 'adminDestroyMultiple']);

        // Admin Affiliate
        Route::get('/admin/affiliate/withdrawals', [AffiliateController::class, 'adminWithdrawals']);
        Route::post('/admin/affiliate/withdrawals/{id}/approve', [AffiliateController::class, 'approve']);
        Route::post('/admin/affiliate/withdrawals/{id}/reject', [AffiliateController::class, 'reject']);
        Route::post('/admin/affiliate/users/{id}/commission-rate', [AffiliateController::class, 'setCommissionRate']);

        // Admin Deposits & Webhook Logs
        Route::get('/admin/users/deposits', [DongtienController::class, 'adminUserDeposits']);
        Route::get('/admin/users/{id}/deposits', [DongtienController::class, 'adminUserDepositHistory']);
        Route::get('/admin/deposits/trace', [DepositController::class, 'traceByTid']);
        Route::get('/admin/deposits', [DepositController::class, 'adminIndex']);
        Route::get('/admin/webhook-logs', [DepositController::class, 'webhookLogs']);

        // Admin Transaction Banks
        Route::get('/admin/transaction-banks', [TransactionBankController::class, 'index']);
        Route::post('/admin/transaction-banks/{id}/manual-credit', [TransactionBankController::class, 'manualCredit']);

        // Admin Bank Auto
        Route::get('/admin/bank-auto/{id}/logs', [BankAutoController::class, 'logs']);
        Route::middleware('throttle:30,1')->group(function () {
            Route::post('/admin/bank-auto/{id}/cancel', [BankAutoController::class, 'cancel']);
            Route::post('/admin/bank-auto/{id}/manual-credit', [BankAutoController::class, 'manualCredit']);
            Route::post('/admin/bank-auto/{id}/approve', [BankAutoController::class, 'approve']);
            Route::post('/admin/bank-auto/{id}/reject', [BankAutoController::class, 'reject']);
            Route::post('/admin/bank-auto/{id}/credit', [BankAutoController::class, 'credit']);
        });
    });

});

// Public API (xác thực qua api_token lưu trong DB)
Route::middleware('api_key')->group(function () {
    Route::post('/v2', [ApiOrderController::class, 'handle']);
    Route::get('/v2', [ApiOrderController::class, 'handle']);
});

// Reseller API routes (xác thực qua api_token, chỉ dành cho role reseller)
Route::middleware('reseller_auth')->prefix('reseller')->group(function () {
    Route::get('/services', [ResellerController::class, 'services']);
    Route::get('/providers/allowed', [ResellerController::class, 'allowedProviders']);
    Route::post('/providers/check', [ResellerController::class, 'checkProvider']);
    Route::post('/orders', [ResellerController::class, 'addOrder'])->middleware('throttle:60,1');
    Route::get('/orders', [ResellerController::class, 'orders']);
    Route::get('/balance', [ResellerController::class, 'balance']);
});

// Super Admin routes (dev only)
Route::middleware(['auth:sanctum', 'super_admin'])->prefix('super-admin')->group(function () {
    Route::get('/providers', [ProviderController::class, 'listSupport']);
    Route::post('/providers/{id}/toggle-supported', [ProviderController::class, 'toggleSupported']);

    // Reseller Permissions (superadmin quản lý)
    Route::get('/reseller-permissions', [ResellerPermissionController::class, 'index']);
    Route::get('/reseller-permissions/{userId}', [ResellerPermissionController::class, 'show']);
    Route::post('/reseller-permissions/{userId}', [ResellerPermissionController::class, 'update']);
    Route::post('/reseller-permissions/{userId}/providers/{providerId}/toggle', [ResellerPermissionController::class, 'toggle']);
    Route::post('/reseller-permissions/{userId}/grant-all', [ResellerPermissionController::class, 'grantAll']);
    Route::post('/reseller-permissions/{userId}/revoke-all', [ResellerPermissionController::class, 'revokeAll']);

    // Partner Providers
    Route::get('/partner-providers/all', [PartnerProviderController::class, 'all']);
    Route::get('/partner-providers', [PartnerProviderController::class, 'index']);
    Route::post('/partner-providers', [PartnerProviderController::class, 'store']);
    Route::get('/partner-providers/{id}', [PartnerProviderController::class, 'show']);
    Route::put('/partner-providers/{id}', [PartnerProviderController::class, 'update']);
    Route::delete('/partner-providers/{id}', [PartnerProviderController::class, 'destroy']);
    Route::post('/partner-providers/{id}/toggle', [PartnerProviderController::class, 'toggle']);
});
