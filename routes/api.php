<?php

use App\Http\Controllers\Api\AffiliateController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankAutoController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\Pay2sController;
use App\Http\Controllers\Api\CategoryGroupController;
use App\Http\Controllers\Api\CodeTransactionController;
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
Route::withoutMiddleware('throttle:api')->group(function () {
    Route::post('/webhook/pay2s', [Pay2sController::class, 'webhook']);
});

// Public routes
Route::middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});
Route::get('/categories/all', [CategoryController::class, 'all']);
Route::get('/category-groups/all', [CategoryGroupController::class, 'all']);
Route::get('/category-groups/get-all', [CategoryGroupController::class, 'getAll']);
Route::get('/services/all', [ServiceController::class, 'all']);
Route::get('/services/form-types', [ServiceController::class, 'formTypes']);
Route::get('/services/platforms', [ServiceController::class, 'platforms']);

Route::post('/get-providers', [ProviderController::class, 'getProvider']);
Route::get('/countries/all', [CountryController::class, 'all']);
Route::get('/settings/all', [SettingController::class, 'all']);
Route::get('/settings/public', [SettingController::class, 'publicIndex']);

// Public Statistics API
Route::get('/statistics/summary', [StatisticsController::class, 'summary']);
Route::get('/statistics/revenue', [StatisticsController::class, 'revenue']);
Route::get('/statistics/daily-breakdown', [StatisticsController::class, 'dailyBreakdown']);

// Public Notifications API
Route::get('/notifications', [NotificationController::class, 'index']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/profile', [AuthController::class, 'profile']);

    // Categories
    Route::get('/categories', [CategoryController::class, 'index']);
    Route::post('/categories', [CategoryController::class, 'store']);
    Route::post('/categories/delete-multiple', [CategoryController::class, 'destroyMultiple']);
    Route::get('/categories/{id}', [CategoryController::class, 'show']);
    Route::post('/categories/{id}', [CategoryController::class, 'update']);
    Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

    // Category Groups
    Route::get('/category-groups', [CategoryGroupController::class, 'index']);
    Route::post('/category-groups', [CategoryGroupController::class, 'store']);
    Route::post('/category-groups/delete-multiple', [CategoryGroupController::class, 'destroyMultiple']);
    Route::get('/category-groups/all', [CategoryGroupController::class, 'all']);
    Route::get('/category-groups/{id}', [CategoryGroupController::class, 'show']);
    Route::post('/category-groups/{id}', [CategoryGroupController::class, 'update']);
    Route::delete('/category-groups/{id}', [CategoryGroupController::class, 'destroy']);

    // Providers
    Route::get('/providers', [ProviderController::class, 'index']);
    Route::post('/providers', [ProviderController::class, 'store']);
    Route::post('/providers/delete-multiple', [ProviderController::class, 'destroyMultiple']);
    Route::get('/providers/{id}', [ProviderController::class, 'show']);
    Route::post('/providers/{id}', [ProviderController::class, 'update']);
    Route::delete('/providers/{id}', [ProviderController::class, 'destroy']);


    // Provider Services
    Route::get('/provider-services', [ProviderServiceController::class, 'index']);
    Route::post('/provider-services', [ProviderServiceController::class, 'store']);
    Route::post('/provider-services/delete-multiple', [ProviderServiceController::class, 'destroyMultiple']);
    Route::get('/provider-services/all', [ProviderServiceController::class, 'all']);
    Route::get('/provider-services/{id}', [ProviderServiceController::class, 'show']);
    Route::post('/provider-services/{id}', [ProviderServiceController::class, 'update']);
    Route::delete('/provider-services/{id}', [ProviderServiceController::class, 'destroy']);

    // Services
    Route::get('/services', [ServiceController::class, 'index']);
    Route::post('/services', [ServiceController::class, 'store']);
    Route::post('/services/delete-multiple', [ServiceController::class, 'destroyMultiple']);
    Route::get('/services/group/{groupId?}', [ServiceController::class, 'getByGroupId']);
    Route::get('/services/{id}', [ServiceController::class, 'show']);
    Route::post('/services/{id}', [ServiceController::class, 'update']);
    Route::delete('/services/{id}', [ServiceController::class, 'destroy']);

    // Countries
    Route::get('/countries', [CountryController::class, 'index']);
    Route::get('/countries/all', [CountryController::class, 'all']);

    // Users
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::post('/users/delete-multiple', [UserController::class, 'destroyMultiple']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::post('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
    Route::post('/users/{id}/reset-password', [UserController::class, 'resetPassword']);
    Route::post('/users/{id}/generate-api-key', [UserController::class, 'generateApiKey']);
    Route::post('/users/{id}/adjust-balance', [UserController::class, 'adjustBalance']);

    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/user/{userId}', [OrderController::class, 'getOrdersByUser']);
    Route::get('/orders/{orderId}/logs', [OrderController::class, 'getOrderLogs']);
    Route::post('/add-order', [OrderController::class, 'addOrder']);
    Route::post('/orders/{orderId}/cancel', [OrderController::class, 'cancelOrder']);

    // Code Transactions
    Route::post('/code-transactions', [CodeTransactionController::class, 'store']);

    // Transactions (Lịch sử giao dịch)
    Route::get('/transactions', [DongtienController::class, 'index']);

    // Deposits (Quản lý nạp tiền)
    Route::get('/deposits', [DepositController::class, 'index']);
    Route::get('/deposits/{id}', [DepositController::class, 'show']);

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/today', [DashboardController::class, 'today']);
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);
    Route::get('/dashboard/total-stats', [DashboardController::class, 'totalStats']);
    Route::get('/dashboard/user', [DashboardController::class, 'userStats']);
    Route::get('/dashboard/recent-logins', [DashboardController::class, 'recentLogins']);
    Route::get('/dashboard/purchased-services', [DashboardController::class, 'userPurchasedServices']);
    Route::get('/dashboard/purchased-categories', [DashboardController::class, 'userPurchasedCategories']);
    Route::get('/dashboard/financial-stats', [DashboardController::class, 'financialStats']);
    Route::get('/dashboard/order-stats', [DashboardController::class, 'orderStats']);
    
    // Notifications (Admin)
    Route::get('/admin/notifications', [NotificationController::class, 'adminIndex']);
    Route::post('/admin/notifications', [NotificationController::class, 'store']);
    Route::put('/admin/notifications/{id}', [NotificationController::class, 'update']);
    Route::post('/admin/notifications/{id}/status', [NotificationController::class, 'updateStatus']);
    Route::delete('/admin/notifications/{id}', [NotificationController::class, 'adminDestroy']);
    Route::post('/admin/notifications/delete-multiple', [NotificationController::class, 'adminDestroyMultiple']);

    // Settings
    Route::get('/settings', [SettingController::class, 'index']);
    Route::post('/settings/save', [SettingController::class, 'save']);
    Route::post('/settings', [SettingController::class, 'update']);
    Route::get('/settings/{key}', [SettingController::class, 'show']);
    Route::delete('/settings/{key}', [SettingController::class, 'destroy']);

    // Upload
    Route::post('/upload/image', [UploadController::class, 'uploadImage']);
    Route::post('/upload/images', [UploadController::class, 'uploadMultipleImages']);
    Route::delete('/upload/image/{filename}', [UploadController::class, 'deleteImage']);

    // Affiliate
    Route::get('/affiliate/dashboard', [AffiliateController::class, 'dashboard']);
    Route::get('/affiliate/commissions', [AffiliateController::class, 'commissions']);
    Route::get('/affiliate/referrals', [AffiliateController::class, 'referrals']);
    Route::get('/affiliate/link', [AffiliateController::class, 'getLink']);
    Route::post('/affiliate/withdraw', [AffiliateController::class, 'withdraw']);
    Route::get('/affiliate/withdrawals', [AffiliateController::class, 'withdrawals']);

    // Admin Affiliate
    Route::get('/admin/affiliate/withdrawals', [AffiliateController::class, 'adminWithdrawals']);
    Route::post('/admin/affiliate/withdrawals/{id}/approve', [AffiliateController::class, 'approve']);
    Route::post('/admin/affiliate/withdrawals/{id}/reject', [AffiliateController::class, 'reject']);

    // Admin Bank Auto
    Route::post('/admin/bank-auto/{id}/approve', [BankAutoController::class, 'approve']);
    Route::post('/admin/bank-auto/{id}/reject', [BankAutoController::class, 'reject']);

});

// Super Admin routes (dev only)
Route::middleware(['auth:sanctum', 'super_admin'])->prefix('super-admin')->group(function () {
    Route::get('/providers', [ProviderController::class, 'listSupport']);
    Route::post('/providers/{id}/toggle-supported', [ProviderController::class, 'toggleSupported']);
});
