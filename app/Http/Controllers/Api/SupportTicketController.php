<?php

namespace App\Http\Controllers\Api;

use App\Models\SupportTicket;
use App\Models\SupportMessage;
use App\Models\SupportAttachment;
use App\Events\NewSupportMessage;
use App\Events\SupportTicketUpdated;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use App\Http\Controllers\Controller;

class SupportTicketController extends Controller
{
    /**
     * Danh sách tickets.
     * Admin: thấy tất cả. User: chỉ thấy của mình.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = SupportTicket::with([
            'user:id,name,email',
            'assignedAdmin:id,name,email',
            'latestMessage',
        ]);

        // User thường chỉ thấy ticket của mình
        if ($user->isUser()) {
            $query->where('user_id', $user->id);
        }

        // Filter theo status
        $status = $request->input('status');
        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        // Filter theo priority
        $priority = $request->input('priority');
        if ($priority) {
            $query->where('priority', $priority);
        }

        // Filter theo category
        $category = $request->input('category');
        if ($category) {
            $query->where('category', $category);
        }

        // Tìm kiếm theo subject
        $search = $request->input('search');
        if ($search) {
            $query->where('subject', 'like', "%{$search}%");
        }

        // Thống kê số lượng từng status
        $statusQuery = SupportTicket::query();
        if ($user->isUser()) {
            $statusQuery->where('user_id', $user->id);
        }
        $statusCountsRaw = $statusQuery
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $statusCounts = [
            'all' => array_sum($statusCountsRaw),
            'open' => $statusCountsRaw['open'] ?? 0,
            'in_progress' => $statusCountsRaw['in_progress'] ?? 0,
            'closed' => $statusCountsRaw['closed'] ?? 0,
        ];

        $perPage = $request->input('per_page', 10);
        $tickets = $query->orderBy('last_message_at', 'desc')
            ->orderBy('id', 'desc')
            ->paginate($perPage);

        return response()->json([
            'data' => $tickets->items(),
            'current_page' => $tickets->currentPage(),
            'per_page' => $tickets->perPage(),
            'total' => $tickets->total(),
            'last_page' => $tickets->lastPage(),
            'status_counts' => $statusCounts,
        ]);
    }

    /**
     * Tạo ticket mới + tin nhắn đầu tiên.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'priority' => ['nullable', 'string', 'in:low,medium,high'],
            'category' => ['nullable', 'string', 'in:general,order,payment,technical,other'],
            'message' => ['required', 'string', 'max:5000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,zip'],
        ]);

        $user = $request->user();

        // Tạo ticket
        $ticket = SupportTicket::create([
            'user_id' => $user->id,
            'subject' => $validated['subject'],
            'status' => SupportTicket::STATUS_OPEN,
            'priority' => $validated['priority'] ?? SupportTicket::PRIORITY_MEDIUM,
            'category' => $validated['category'] ?? SupportTicket::CATEGORY_GENERAL,
            'last_message_at' => now(),
        ]);

        // Tạo tin nhắn đầu tiên
        $message = SupportMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'message' => $validated['message'],
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

        $message->load(['user:id,name,email,role', 'attachments']);
        $ticket->load(['user:id,name,email', 'latestMessage']);

        // Broadcast tin nhắn mới
        broadcast(new NewSupportMessage($message));

        return response()->json([
            'message' => 'Tạo ticket hỗ trợ thành công.',
            'data' => $ticket,
        ], 201);
    }

    /**
     * Chi tiết ticket.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $ticket = SupportTicket::with([
            'user:id,name,email',
            'assignedAdmin:id,name,email',
        ])->find($id);

        if (!$ticket) {
            return response()->json(['message' => 'Ticket không tồn tại.'], 404);
        }

        // User thường chỉ xem được ticket của mình
        if ($user->isUser() && $ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Bạn không có quyền xem ticket này.'], 403);
        }

        return response()->json([
            'data' => $ticket,
        ]);
    }

    /**
     * Đóng ticket.
     */
    public function close(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $ticket = SupportTicket::find($id);

        if (!$ticket) {
            return response()->json(['message' => 'Ticket không tồn tại.'], 404);
        }

        // User thường chỉ đóng được ticket của mình
        if ($user->isUser() && $ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Bạn không có quyền đóng ticket này.'], 403);
        }

        if ($ticket->isClosed()) {
            return response()->json(['message' => 'Ticket đã được đóng trước đó.'], 400);
        }

        $ticket->close();

        broadcast(new SupportTicketUpdated($ticket));

        return response()->json([
            'message' => 'Đóng ticket thành công.',
            'data' => $ticket,
        ]);
    }

    /**
     * Mở lại ticket.
     */
    public function reopen(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $ticket = SupportTicket::find($id);

        if (!$ticket) {
            return response()->json(['message' => 'Ticket không tồn tại.'], 404);
        }

        if ($user->isUser() && $ticket->user_id !== $user->id) {
            return response()->json(['message' => 'Bạn không có quyền mở lại ticket này.'], 403);
        }

        if (!$ticket->isClosed()) {
            return response()->json(['message' => 'Ticket chưa được đóng.'], 400);
        }

        $ticket->reopen();

        broadcast(new SupportTicketUpdated($ticket));

        return response()->json([
            'message' => 'Mở lại ticket thành công.',
            'data' => $ticket,
        ]);
    }

    /**
     * Admin assign ticket cho mình.
     */
    public function assign(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Chỉ admin mới có thể assign ticket.'], 403);
        }

        $ticket = SupportTicket::find($id);

        if (!$ticket) {
            return response()->json(['message' => 'Ticket không tồn tại.'], 404);
        }

        $ticket->update([
            'assigned_admin_id' => $user->id,
        ]);

        // Chuyển sang in_progress nếu đang open
        if ($ticket->isOpen()) {
            $ticket->update(['status' => SupportTicket::STATUS_IN_PROGRESS]);
        }

        $ticket->load(['user:id,name,email', 'assignedAdmin:id,name,email']);

        broadcast(new SupportTicketUpdated($ticket));

        return response()->json([
            'message' => 'Assign ticket thành công.',
            'data' => $ticket,
        ]);
    }
}
