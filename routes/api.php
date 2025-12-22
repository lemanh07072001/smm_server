<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CategoryGroupController;
use App\Http\Controllers\Api\CodeTransactionController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProviderController;
use App\Http\Controllers\Api\ProviderServiceController;
use App\Http\Controllers\Api\ServiceController;
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

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::get('/categories/all', [CategoryController::class, 'all']);
Route::get('/category-groups/all', [CategoryGroupController::class, 'all']);
Route::get('/services/all', [ServiceController::class, 'all']);
Route::post('/get-providers', [ProviderController::class, 'getProvider']);

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
    Route::get('/services/platforms', [ServiceController::class, 'platforms']);

    Route::get('/services/{id}', [ServiceController::class, 'show']);
    Route::post('/services/{id}', [ServiceController::class, 'update']);
    Route::delete('/services/{id}', [ServiceController::class, 'destroy']);

    // Users
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::post('/users/delete-multiple', [UserController::class, 'destroyMultiple']);
    Route::get('/users/{id}', [UserController::class, 'show']);
    Route::post('/users/{id}', [UserController::class, 'update']);
    Route::delete('/users/{id}', [UserController::class, 'destroy']);
    Route::post('/users/{id}/reset-password', [UserController::class, 'resetPassword']);
    Route::post('/users/{id}/generate-api-key', [UserController::class, 'generateApiKey']);

    // Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/user/{userId}', [OrderController::class, 'getOrdersByUser']);
    Route::post('/add-order', [OrderController::class, 'addOrder']);

    // Code Transactions
    Route::post('/code-transactions', [CodeTransactionController::class, 'store']);
});
