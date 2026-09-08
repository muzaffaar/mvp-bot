<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class TaskCommentAttachment extends Model { use HasFactory; protected $fillable=['task_comment_id','type','path','url','mime_type','size','telegram_file_id','telegram_file_unique_id','message_id','duration','transcription']; protected function casts(): array { return ['duration'=>'integer','size'=>'integer']; } public function comment(): BelongsTo { return $this->belongsTo(TaskComment::class,'task_comment_id'); } }
