<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReportDashboardDaily extends Model
{
    use HasFactory;

    protected $table = 'report_dashboard_daily';

    protected $fillable = [
        'date_at',
        'total_orders',
        'order_pending',
        'order_processing',
        'order_in_progress',
        'order_completed',
        'order_partial',
        'order_canceled',
        'order_refunded',
        'order_failed',
        'total_revenue',
        'total_charge',
        'total_cost',
        'total_profit',
        'total_refund',
        'total_customers',
        'new_customers',
        'active_customers',
        'total_deposits',
        'deposit_amount',
    ];

    protected $casts = [
        'date_at' => 'integer',
        'total_orders' => 'integer',
        'order_pending' => 'integer',
        'order_processing' => 'integer',
        'order_in_progress' => 'integer',
        'order_completed' => 'integer',
        'order_partial' => 'integer',
        'order_canceled' => 'integer',
        'order_refunded' => 'integer',
        'order_failed' => 'integer',
        'total_revenue' => 'decimal:2',
        'total_charge' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'total_profit' => 'decimal:2',
        'total_refund' => 'decimal:2',
        'total_customers' => 'integer',
        'new_customers' => 'integer',
        'active_customers' => 'integer',
        'total_deposits' => 'integer',
        'deposit_amount' => 'decimal:2',
    ];
}
