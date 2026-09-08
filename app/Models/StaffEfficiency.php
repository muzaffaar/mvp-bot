<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffEfficiency extends Model
{
    use HasFactory;

    protected $fillable = [
        'staff_id',
        'period_start',
        'period_end',
        'on_time_rate',
        'first_pass_rate',
        'volume',
        'response_speed',
        'created_tasks',
        'completed_tasks',
        'overdue_tasks',
        'reworked_tasks',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',

            'on_time_rate' => 'decimal:2',
            'first_pass_rate' => 'decimal:2',
            'volume' => 'decimal:2',
            'response_speed' => 'decimal:2',

            'calculated_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }
}
