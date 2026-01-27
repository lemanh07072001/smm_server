<?php

namespace App\Http\Controllers\Api;

use App\Models\SupportTicket;
use App\Models\SupportMessage;
use App\Models\SupportAttachment;
use App\Events\NewSupportMessage;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;

class SupportMessageController extends Controller
{
    /**
     * Danh sách tin nhắn của ticket (paginated).
     */
    public function index(Request $request, int $ticketId): JsonResponse
    {
        $user = $request->user();

        $ticket = SupportTicket::find($ticketId);

        if (!$ticket) {
            return response()->json(['message' => 'Ticket không tồn tại.'], 404);
        }

        // User thường chỉ xem được tin nhắn của ticket mình
        if ($user->isUser() && $ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Bạn không có quyền xem ticket này.'], 403);
        }

        $perPage = $request->input('per_page', 20);

        $messages = SupportMessage::with([
            'user:id,name,email,role',
            'attachments',
        ])
            ->where('ticket_id', $ticketId)
            ->orderBy('id', 'asc')
            ->paginate($perPage);

        return response()->json([
            'data' => $messages->items(),
            'current_page' => $messages->currentPage(),
            'per_page' => $messages->perPage(),
            'total' => $messages->total(),
            'last_page' => $messages->lastPage(),
        ]);
    }

    /**
     * Gửi tin nhắn mới trong ticket.
     */
    public function store(Request $request, int $ticketId): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,zip'],
        ]);

        // Phải có message hoặc attachments
        if (empty($validated['message']) && !$request->hasFile('attachments')) {
            return response()->json([
                'message' => 'Phải có nội dung tin nhắn hoặc file đính kèm.',
            ], 422);
        }

        $user = $request->user();

        $ticket = SupportTicket::find($ticketId);

        if (!$ticket) {
            return response()->json(['message' => 'Ticket không tồn tại.'], 404);
        }

        // User thường chỉ gửi được tin nhắn trong ticket mình
        if ($user->isUser() && $ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Bạn không có quyền gửi tin nhắn trong ticket này.'], 403);
        }

        // Không cho gửi tin nhắn khi ticket đã đóng
        if ($ticket->isClosed()) {
            return response()->json(['message' => 'Ticket đã được đóng. Vui lòng mở lại ticket để tiếp tục.'], 400);
        }

        // Tạo tin nhắn
        $message = SupportMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'message' => $validated['message'] ?? null,
        ]);

        // Upload attachments nếu có
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('public/support/' . $ticket->id);
                SupportAttachment::create([
                    'message_id' => $message->id,
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'file_type' => $file->getClientOriginalExtension(),
                    'file_size' => $file->getSize(),
                ]);
            }
        }

        // Cập nhật last_message_at
        $ticket->update(['last_message_at' => now()]);

        // Nếu admin reply lần đầu, chuyển ticket sang in_progress
        if ($user->isAdmin() && $ticket->isOpen()) {
            $ticket->update([
                'status' => SupportTicket::STATUS_IN_PROGRESS,
                'assigned_admin_id' => $ticket->assigned_admin_id ?? $user->id,
            ]);
        }

        $message->load(['user:id,name,email,role', 'attachments']);

        // Broadcast tin nhắn mới qua WebSocket
        broadcast(new NewSupportMessage($message));

        return response()->json([
            'message' => 'Gửi tin nhắn thành công.',
            'data' => $message,
        ], 201);
    }

    /**
     * Đánh dấu đã đọc tất cả tin nhắn trong ticket.
     */
    public function markAsRead(Request $request, int $ticketId): JsonResponse
    {
        $user = $request->user();

        $ticket = SupportTicket::find($ticketId);

        if (!$ticket) {
            return response()->json(['message' => 'Ticket không tồn tại.'], 404);
        }

        if ($user->isUser() && $ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Bạn không có quyền thực hiện thao tác này.'], 403);
        }

        // Đánh dấu đã đọc tất cả tin nhắn không phải của mình
        SupportMessage::where('ticket_id', $ticketId)
            ->where('user_id', '!=', $user->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json([
            'message' => 'Đã đánh dấu đọc tất cả tin nhắn.',
        ]);
    }

    /**
     * Đếm số tin nhắn chưa đọc.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            // Admin: đếm tất cả tin nhắn chưa đọc từ user (không phải admin gửi)
            $count = SupportMessage::where('is_read', false)
                ->whereHas('user', function ($q) {
                    $q->where('role', '!=', 0); // Không phải admin
                })
                ->count();
        } else {
            // User: đếm tin nhắn chưa đọc trong các ticket của mình
            $ticketIds = SupportTicket::where('user_id', $user->id)->pluck('id');
            $count = SupportMessage::whereIn('ticket_id', $ticketIds)
                ->where('user_id', '!=', $user->id)
                ->where('is_read', false)
                ->count();
        }

        return response()->json([
            'unread_count' => $count,
        ]);
    }
}
