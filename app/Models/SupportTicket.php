<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupportTicket extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_CLOSED = 'closed';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MEDIUM = 'medium';
    public const PRIORITY_HIGH = 'high';

    public const CATEGORY_GENERAL = 'general';
    public const CATEGORY_ORDER = 'order';
    public const CATEGORY_PAYMENT = 'payment';
    public const CATEGORY_TECHNICAL = 'technical';
    public const CATEGORY_OTHER = 'other';

    protected $fillable = [
        'user_id',
        'assigned_admin_id',
        'subject',
        'status',
        'priority',
        'category',
        'last_message_at',
        'closed_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'assigned_admin_id' => 'integer',
        'last_message_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_admin_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(SupportMessage::class, 'ticket_id')->latestOfMany();
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function close(): void
    {
        $this->update([
            'status' => self::STATUS_CLOSED,
            'closed_at' => now(),
        ]);
    }

    public function reopen(): void
    {
        $this->update([
            'status' => self::STATUS_OPEN,
            'closed_at' => null,
        ]);
    }

    public static function statusLabels(): array
    {
        return [
            self::STATUS_OPEN => 'Mở',
            self::STATUS_IN_PROGRESS => 'Đang xử lý',
            self::STATUS_CLOSED => 'Đã đóng',
        ];
    }

    public static function priorityLabels(): array
    {
        return [
            self::PRIORITY_LOW => 'Thấp',
            self::PRIORITY_MEDIUM => 'Trung bình',
            self::PRIORITY_HIGH => 'Cao',
        ];
    }

    public static function categoryLabels(): array
    {
        return [
            self::CATEGORY_GENERAL => 'Chung',
            self::CATEGORY_ORDER => 'Đơn hàng',
            self::CATEGORY_PAYMENT => 'Thanh toán',
            self::CATEGORY_TECHNICAL => 'Kỹ thuật',
            self::CATEGORY_OTHER => 'Khác',
        ];
    }
}
