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
     * Chat nhanh - User chỉ gửi message, hệ thống tự tạo/tìm ticket.
     */
    public function chat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,zip'],
        ]);

        if (empty($validated['message']) && !$request->hasFile('attachments')) {
            return response()->json([
                'message' => 'Phải có nội dung tin nhắn hoặc file đính kèm.',
            ], 422);
        }

        $user = $request->user();

        // Tìm ticket đang mở/đang xử lý của user
        $ticket = SupportTicket::where('user_id', $user->id)
            ->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_IN_PROGRESS])
            ->orderBy('id', 'desc')
            ->first();

        // Nếu chưa có ticket, tự tạo mới
        if (!$ticket) {
            $ticket = SupportTicket::create([
                'user_id' => $user->id,
                'subject' => 'Hỗ trợ từ ' . $user->name,
                'status' => SupportTicket::STATUS_OPEN,
                'priority' => SupportTicket::PRIORITY_MEDIUM,
                'category' => SupportTicket::CATEGORY_GENERAL,
                'last_message_at' => now(),
            ]);
        }

        // Tạo tin nhắn
        $message = SupportMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'message' => $validated['message'] ?? null,
        ]);

        // Upload attachments
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

        $ticket->update(['last_message_at' => now()]);

        $message->load(['user:id,name,email,role', 'attachments']);

        try {
            broadcast(new NewSupportMessage($message));
        } catch (\Exception) {
        }

        return response()->json([
            'message' => 'Gửi tin nhắn thành công.',
            'data' => $message,
            'ticket_id' => $ticket->id,
        ], 201);
    }

    /**
     * Lấy lịch sử chat của user (tự tìm ticket đang mở).
     */
    public function chatHistory(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = $request->input('per_page', 20);

        // Tìm ticket đang mở/đang xử lý của user
        $ticket = SupportTicket::where('user_id', $user->id)
            ->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_IN_PROGRESS])
            ->orderBy('id', 'desc')
            ->first();

        if (!$ticket) {
            return response()->json([
                'data' => [],
                'ticket_id' => null,
                'current_page' => 1,
                'per_page' => $perPage,
                'total' => 0,
                'last_page' => 1,
            ]);
        }

        $messages = SupportMessage::with([
            'user:id,name,email,role',
            'attachments',
        ])
            ->where('ticket_id', $ticket->id)
            ->orderBy('id', 'asc')
            ->paginate($perPage);

        return response()->json([
            'data' => $messages->items(),
            'ticket_id' => $ticket->id,
            'ticket_status' => $ticket->status,
            'current_page' => $messages->currentPage(),
            'per_page' => $messages->perPage(),
            'total' => $messages->total(),
            'last_page' => $messages->lastPage(),
        ]);
    }

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
        try {
            broadcast(new NewSupportMessage($message));
        } catch (\Exception) {
        }

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

    /**
     * Admin: danh sách tất cả cuộc hội thoại (tickets có tin nhắn).
     */
    public function adminConversations(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Chỉ admin mới có quyền truy cập.'], 403);
        }

        $status = $request->input('status');
        $search = $request->input('search');
        $perPage = $request->input('per_page', 10);

        $query = SupportTicket::with([
            'user:id,name,email',
            'assignedAdmin:id,name,email',
            'latestMessage.user:id,name,role',
        ])
        ->withCount(['messages as unread_count' => function ($q) {
            $q->where('is_read', false)
              ->whereHas('user', function ($q2) {
                  $q2->where('role', '!=', 0);
              });
        }]);

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        } else {
            // Mặc định chỉ hiện ticket đang mở/đang xử lý
            $query->whereIn('status', [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_IN_PROGRESS]);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('subject', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($q2) use ($search) {
                      $q2->where('name', 'like', "%{$search}%")
                         ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        $tickets = $query->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $tickets->items(),
            'current_page' => $tickets->currentPage(),
            'per_page' => $tickets->perPage(),
            'total' => $tickets->total(),
            'last_page' => $tickets->lastPage(),
        ]);
    }

    /**
     * Admin: reply tin nhắn vào ticket bất kỳ.
     */
    public function adminReply(Request $request, int $ticketId): JsonResponse
    {
        $user = $request->user();

        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Chỉ admin mới có quyền trả lời.'], 403);
        }

        $validated = $request->validate([
            'message' => ['nullable', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,zip'],
        ]);

        if (empty($validated['message']) && !$request->hasFile('attachments')) {
            return response()->json([
                'message' => 'Phải có nội dung tin nhắn hoặc file đính kèm.',
            ], 422);
        }

        $ticket = SupportTicket::find($ticketId);

        if (!$ticket) {
            return response()->json(['message' => 'Ticket không tồn tại.'], 404);
        }

        // Tạo tin nhắn
        $message = SupportMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'message' => $validated['message'] ?? null,
        ]);

        // Upload attachments
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

        // Cập nhật ticket
        $ticket->update([
            'last_message_at' => now(),
            'assigned_admin_id' => $ticket->assigned_admin_id ?? $user->id,
        ]);

        // Chuyển sang in_progress nếu đang open
        if ($ticket->isOpen()) {
            $ticket->update(['status' => SupportTicket::STATUS_IN_PROGRESS]);
        }

        $message->load(['user:id,name,email,role', 'attachments']);

        try {
            broadcast(new NewSupportMessage($message));
        } catch (\Exception) {
        }

        return response()->json([
            'message' => 'Trả lời thành công.',
            'data' => $message,
        ], 201);
    }
}
