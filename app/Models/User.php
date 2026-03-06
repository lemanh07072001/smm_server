<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 0;
    public const ROLE_USER = 1;
    public const ROLE_RESELLER = 2;
    public const ROLE_SUPER_ADMIN = 3;
    public const ROLES = [self::ROLE_ADMIN, self::ROLE_USER, self::ROLE_RESELLER, self::ROLE_SUPER_ADMIN];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'balance',
        'discount',
        'api_key',
        'is_active',
        'referred_by',
        'affiliate_balance',
        'affiliate_commission_rate',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'role' => 'integer',
        'balance' => 'decimal:6',
        'discount' => 'decimal:2',
        'is_active' => 'boolean',
        'referred_by' => 'integer',
        'affiliate_balance' => 'decimal:6',
        'affiliate_commission_rate' => 'decimal:2',
    ];

    /**
     * Check if user is admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Check if user is regular user.
     */
    public function isUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    /**
     * Check if user is reseller (role 3).
     */
    public function isReseller(): bool
    {
        return $this->role === self::ROLE_RESELLER;
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    /**
     * Lấy giá bán riêng của user này cho một service.
     * Trả về null nếu không có giá riêng.
     */
    public function getPriceForService(int $serviceId): ?string
    {
        return $this->servicePrices()
            ->where('service_id', $serviceId)
            ->value('sell_rate');
    }

    /**
     * Get the orders for the user.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get the login histories for the user.
     */
    public function loginHistories(): HasMany
    {
        return $this->hasMany(LoginHistory::class);
    }

    /**
     * Get the user who referred this user.
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    /**
     * Get users referred by this user.
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by');
    }

    /**
     * Get affiliate commissions earned by this user.
     */
    public function affiliateCommissions(): HasMany
    {
        return $this->hasMany(AffiliateCommission::class);
    }

    /**
     * Get affiliate withdrawal requests.
     */
    public function affiliateWithdrawals(): HasMany
    {
        return $this->hasMany(AffiliateWithdrawal::class);
    }

    /**
     * Giá bán riêng theo từng service (dành cho role reseller).
     */
    public function servicePrices(): HasMany
    {
        return $this->hasMany(UserServicePrice::class);
    }
}
