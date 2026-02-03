<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    /**
     * Get all active notifications.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Notification::query()->active();

        // Filter by type if provided
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        $notifications = $query
            ->orderBy('order', 'asc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $notifications,
        ]);
    }

    /**
     * Create a new notification.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|string|in:modal,toast,banner,alert',
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'status' => 'nullable|string|in:active,inactive',
            'order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $notification = Notification::create($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Notification created successfully',
            'data' => $notification,
        ], 201);
    }

    /**
     * Update notification.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'nullable|string|in:modal,toast,banner,alert',
            'title' => 'nullable|string|max:255',
            'message' => 'nullable|string',
            'status' => 'nullable|string|in:active,inactive',
            'order' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $notification = Notification::findOrFail($id);
        $notification->update($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Notification updated successfully',
            'data' => $notification,
        ]);
    }

    /**
     * Update notification status.
     */
    public function updateStatus(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $notification = Notification::findOrFail($id);
        $notification->update(['status' => $request->status]);

        return response()->json([
            'success' => true,
            'message' => 'Notification status updated successfully',
            'data' => $notification,
        ]);
    }

    /**
     * Delete a notification.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $notification = Notification::findOrFail($id);
        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notification deleted successfully',
        ]);
    }

    /**
     * Get all notifications - for management.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $query = Notification::query();

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $notifications = $query
            ->orderBy('order', 'asc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $notifications,
        ]);
    }

    /**
     * Delete a notification.
     */
    public function adminDestroy(Request $request, $id): JsonResponse
    {
        $notification = Notification::findOrFail($id);
        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notification deleted successfully',
        ]);
    }

    /**
     * Delete multiple notifications.
     */
    public function adminDestroyMultiple(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array',
            'ids.*' => 'exists:notifications,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $deleted = Notification::whereIn('id', $request->ids)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notifications deleted successfully',
            'deleted_count' => $deleted,
        ]);
    }
}
