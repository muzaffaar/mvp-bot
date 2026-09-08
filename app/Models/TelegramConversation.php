<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramConversation extends Model
{
    use HasFactory;
    
    protected $fillable = [
        'staff_id',
        'telegram_chat_id',
        'state',
        'context',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'last_activity_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(
            Staff::class
        );
    }

    public function reset(): void
    {
        $this->update([
            'state' => 'idle',
            'context' => null,
            'last_activity_at' => now(),
        ]);
    }
}
