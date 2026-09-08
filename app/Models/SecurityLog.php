<?php

namespace App\Models;

use App\Enums\SecurityLogEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityLog extends Model
{
    use HasFactory;

    /*
     * Security logs are immutable audit records.
     * Therefore we don't use updated_at.
     */
    public const UPDATED_AT = null;

    protected $fillable = [
        'staff_id',
        'event_type',
        'description',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => SecurityLogEventType::class,
            'created_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class);
    }

    public static function record(
        SecurityLogEventType $eventType,
        ?int $staffId,
        ?string $description = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): self {
        return static::create([
            'staff_id' => $staffId,
            'event_type' => $eventType,
            'description' => $description,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }
}
