<?php
namespace App\Telegram\Callbacks;

use App\AI\Services\ConversationService;
use App\Enums\Permission;
use App\Enums\TelegramConversationState;
use App\Enums\TaskStatus;
use App\Models\Staff;
use App\Models\Task;
use App\Services\Task\TaskManagementService;
use App\Telegram\Services\TelegramClient;
use App\Telegram\Callbacks\TaskStatusCallback;
use App\Support\TashkentDateTime;
use DomainException;
use Illuminate\Support\Facades\Log;
use Throwable;

class TaskManagementCallback
{
    public function __construct(
        private readonly TaskManagementService $service,
        private readonly ConversationService $conversations,
        private readonly TelegramClient $telegram,
        private readonly TaskStatusCallback $taskStatus,
    ) {}

    private function requirePermission(Staff $staff, Permission $permission): void
    {
        if (! $staff->can($permission->value)) {
            throw new DomainException('Bu amal uchun ruxsatingiz yo\'q.');
        }
    }

    private function has(Staff $staff, Permission $permission): bool
    {
        return $staff->can($permission->value);
    }

    public function handle(Staff $staff, string $data, string $callbackId, int $chatId, int $messageId): void
    {
        try {
            if (preg_match('/^tm:list:(my|given|progress|due|overdue|closed):(first|last|\d+)$/', $data, $m)) { $this->list($staff,$m[1],$m[2],$callbackId,$chatId,$messageId); return; }
            if (preg_match('/^tm:list:given_search:(first|last|\d+)$/', $data, $m)) { $this->listGivenSearch($staff,$m[1],$callbackId,$chatId,$messageId); return; }
            if ($data === 'tm:search:given') { $this->promptGivenSearch($staff,$callbackId,$chatId,$messageId); return; }
            if ($data === 'tm:home') { $this->home($staff,$callbackId,$chatId,$messageId); return; }
            if (preg_match('/^tm:view:(\d+)$/',$data,$m)) { $this->view($staff,(int)$m[1],$callbackId,$chatId,$messageId); return; }
            if (preg_match('/^tm:status:(\d+):(accepted|in_progress|awaiting_acceptance)$/',$data,$m)) { $this->statusAction($staff,(int)$m[1],TaskStatus::from($m[2]),$callbackId,$chatId,$messageId); return; }
            if (preg_match('/^tm:history:(\d+)$/',$data,$m)) { $this->history($staff,(int)$m[1],$callbackId,$chatId,$messageId); return; }
            if (preg_match('/^tm:comments:(\d+)$/',$data,$m)) { $this->comments($staff,(int)$m[1],$callbackId,$chatId,$messageId); return; }
            if (preg_match('/^tm:comment:add:(\d+)$/',$data,$m)) { $this->waitInput($staff,$chatId,$callbackId,$messageId,TelegramConversationState::WAITING_TASK_MANAGEMENT_COMMENT,['task_id'=>(int)$m[1]]); return; }
            if (preg_match('/^tm:edit:(title|description):(\d+)$/',$data,$m)) { $this->waitInput($staff,$chatId,$callbackId,$messageId,TelegramConversationState::WAITING_TASK_MANAGEMENT_INPUT,['task_id'=>(int)$m[2],'action'=>'edit','field'=>$m[1]]); return; }
            if (preg_match('/^tm:deadline:(\d+)$/',$data,$m)) { $this->deadlineMenu($staff,(int)$m[1],$callbackId,$chatId,$messageId); return; }
            if (preg_match('/^tm:deadline:set:(\d+):(\d+)$/',$data,$m)) { $this->setDeadline($staff,(int)$m[1],(int)$m[2],$callbackId,$chatId,$messageId); return; }
            if (preg_match('/^tm:reassign:(\d+):(first|last|\d+)$/',$data,$m)) { $this->reassignList($staff,(int)$m[1],$m[2],$callbackId,$chatId,$messageId); return; }
            if (preg_match('/^tm:reassign:set:(\d+):(\d+)$/',$data,$m)) { $this->reassign($staff,(int)$m[1],(int)$m[2],$callbackId,$chatId,$messageId); return; }
            if (preg_match('/^tm:cancel:(\d+)$/',$data,$m)) { $this->cancelConfirm($staff,(int)$m[1],$callbackId,$chatId,$messageId); return; }
            if (preg_match('/^tm:cancel:yes:(\d+)$/',$data,$m)) { $this->cancel($staff,(int)$m[1],$callbackId,$chatId,$messageId); return; }
            if (preg_match('/^tm:remind:(\d+)$/',$data,$m)) { $this->remind($staff,(int)$m[1],$callbackId,$chatId,$messageId); return; }
            $this->telegram->answerCallbackQuery($callbackId,'Nomaʼlum boshqaruv amali.');
        } catch (DomainException $e) { $this->telegram->answerCallbackQuery($callbackId,'❌ '.$e->getMessage()); }
          catch (Throwable $e) { report($e); Log::error('Task management callback failed',['data'=>$data,'staff_id'=>$staff->id,'error'=>$e->getMessage()]); $this->telegram->answerCallbackQuery($callbackId,'❌ Amalni bajarishda xatolik yuz berdi.'); }
    }

    public function handleInput(Staff $staff, int $chatId, string $text, array $attachments = []): bool
    {
        $conversation=$this->conversations->getOrCreate($staff,$chatId);
        $context=$conversation->context ?? [];
        if ($conversation->state === TelegramConversationState::WAITING_TASK_MANAGEMENT_COMMENT->value) {
            $this->requirePermission($staff, Permission::TaskCommentCreate);
            $task=$this->service->taskForViewer((int)($context['task_id']??0),$staff);
            if (!$task) throw new DomainException('Vazifa topilmadi.');
            if (trim($text) === '' && $attachments === []) throw new DomainException('Izoh yoki media yuboring.');
            $this->service->addCommentWithAttachments($task,$staff,$text,$attachments);
            $conversation->reset();
            $this->telegram->sendMessage($chatId,'✅ Izoh saqlandi.');
            return true;
        }
        if ($conversation->state === TelegramConversationState::WAITING_TASK_MANAGEMENT_ASSIGNEE_SEARCH->value) {
            $this->requirePermission($staff, Permission::TaskCreate);
            $term = trim($text);
            if ($term === '') throw new DomainException('Qidiruv uchun xodim ismini yozing.');
            $conversation->update(['state'=>TelegramConversationState::IDLE->value,'context'=>['given_search_term'=>$term],'last_activity_at'=>now()]);
            $this->renderGivenSearchResults($staff,$chatId,$term,1);
            return true;
        }
        if ($conversation->state === TelegramConversationState::WAITING_TASK_MANAGEMENT_INPUT->value && ($context['action']??null)==='edit') {
            $this->requirePermission($staff, Permission::TaskUpdate);
            $task=$this->service->taskForViewer((int)($context['task_id']??0),$staff);
            if (!$task) throw new DomainException('Vazifa topilmadi.');
            $task=$this->service->updateField($task,$staff,(string)$context['field'],$text);
            $conversation->reset();
            $this->service->notifyAssigneeOfChange($task,'✏️ <b>Vazifa yangilandi</b>');
            $this->telegram->sendMessage($chatId,'✅ Vazifa yangilandi.');
            return true;
        }
        return false;
    }

    public function home(Staff $staff,string $cb,int $chat,int $mid): void { $this->telegram->answerCallbackQuery($cb); $this->clean($chat,$mid); $this->sendHome($chat,$staff); }
    public function sendHome(int $chat, ?Staff $staff = null): void {
        if ($staff && ! $this->has($staff, Permission::TaskView)) { $this->telegram->sendMessage($chat, '❌ Vazifalarni ko‘rish uchun ruxsatingiz yo‘q.'); return; }
        $firstRow = [['text'=>'📌 Mening vazifalarim','callback_data'=>'tm:list:my:1']];
        // "Men bergan" (tasks I assigned to others) only makes sense for staff
        // who can actually create/assign tasks — everyone else would just see
        // an empty list.
        if ($staff && $this->has($staff, Permission::TaskCreate)) {
            $firstRow[] = ['text'=>'📤 Men bergan','callback_data'=>'tm:list:given:1'];
        }
        $this->telegram->sendMessage($chat,"📋 <b>Vazifalarni boshqarish</b>\n\nKerakli bo‘limni tanlang:",[ 'inline_keyboard'=>[
        $firstRow,
        [['text'=>'⏳ Jarayondagi','callback_data'=>'tm:list:progress:1'],['text'=>'⚠️ Muddati yaqin','callback_data'=>'tm:list:due:1']],
        [['text'=>'🔴 Muddati o‘tgan','callback_data'=>'tm:list:overdue:1'],['text'=>'📁 Yopilgan','callback_data'=>'tm:list:closed:1']],
    ]],'HTML'); }

    private function list(Staff $staff,string $type,string|int $page,string $cb,int $chat,int $mid): void {
        $this->requirePermission($staff, Permission::TaskView);
        $this->telegram->answerCallbackQuery($cb); $this->clean($chat,$mid);
        $query=Task::query()->where(function($q)use($staff,$type){
            match($type){
                'my'=>$q->where('assignee_id',$staff->id)->where('status','!=',TaskStatus::CLOSED->value),
                'given'=>$q->where('assignor_id',$staff->id)->where('status','!=',TaskStatus::CLOSED->value),
                'progress'=>$q->where('assignee_id',$staff->id)->where('status','!=',TaskStatus::CLOSED->value)->where(function ($statusQuery) {
                    // Explicitly include IN_PROGRESS first. This is the primary meaning of
                    // "Jarayondagi" and avoids accidentally losing these tasks when the
                    // query is changed later.
                    $statusQuery->where('status', TaskStatus::IN_PROGRESS->value)
                        ->orWhere('status', TaskStatus::ACCEPTED->value)
                        ->orWhere('status', TaskStatus::RETURNED->value);
                }),
                'due'=>$q->where(function($x)use($staff){$x->where('assignee_id',$staff->id)->orWhere('assignor_id',$staff->id);})->where('status','!=',TaskStatus::CLOSED->value)->whereNotNull('deadline')->whereBetween('deadline',[now(),now()->addDay()]),
                'overdue'=>$q->where(function($x)use($staff){$x->where('assignee_id',$staff->id)->orWhere('assignor_id',$staff->id);})->whereNotNull('deadline')->where('deadline','<',now())->whereNotIn('status',[TaskStatus::CLOSED->value,TaskStatus::CANCELLED->value]),
                'closed'=>$q->where(function($x)use($staff){$x->where('assignee_id',$staff->id)->orWhere('assignor_id',$staff->id);})->where('status',TaskStatus::CLOSED->value),
            };
        });
        $perPage = 8;
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $resolvedPage = match ((string) $page) {
            'first' => 1,
            'last' => $lastPage,
            default => min(max(1, (int) $page), $lastPage),
        };
        $tasks=$query->orderByRaw('deadline IS NULL, deadline')->paginate($perPage,['*'],'page',$resolvedPage);
        $labels=['my'=>'📌 Mening vazifalarim','given'=>'📤 Men bergan vazifalar','progress'=>'⏳ Jarayondagi vazifalar','due'=>'⚠️ Muddati yaqin','overdue'=>'🔴 Muddati o‘tgan','closed'=>'📁 Yopilgan vazifalar'];
        if($tasks->isEmpty()){ $this->telegram->sendMessage($chat,"{$labels[$type]}\n\nVazifa topilmadi.",['inline_keyboard'=>[[['text'=>'⬅️ Menyu','callback_data'=>'tm:home']]]],'HTML'); return; }
        $kb=[]; foreach($tasks as $task){$title=mb_strimwidth($task->title,0,32,'…');$deadline=$task->deadline ? TashkentDateTime::short($task->deadline) : '—';$kb[]=[['text'=>"{$task->task_number} · {$title} · {$deadline}",'callback_data'=>"tm:view:{$task->id}"]];}
        $nav=[];
        if($tasks->currentPage()>1) $nav[]=['text'=>'⏮','callback_data'=>"tm:list:{$type}:first"];
        if($tasks->currentPage()>1) $nav[]=['text'=>'⬅️','callback_data'=>"tm:list:{$type}:".($tasks->currentPage()-1)];
        $nav[]=['text'=>$tasks->currentPage().'/'.$tasks->lastPage(),'callback_data'=>'tm:noop'];
        if($tasks->hasMorePages()) $nav[]=['text'=>'➡️','callback_data'=>"tm:list:{$type}:".($tasks->currentPage()+1)];
        if($tasks->currentPage()<$tasks->lastPage()) $nav[]=['text'=>'⏭','callback_data'=>"tm:list:{$type}:last"];
        $kb[]=$nav;
        if ($type === 'given') {
            $kb[]=[['text'=>'🔍 Bajaruvchi bo‘yicha qidirish','callback_data'=>'tm:search:given']];
        }
        $kb[]=[['text'=>'⬅️ Menyu','callback_data'=>'tm:home']];
        $this->telegram->sendMessage($chat,"<b>{$labels[$type]}</b>\n\nVazifani tanlang:",['inline_keyboard'=>$kb],'HTML');
    }

    /**
     * Prompt the assignor (task creator) for an assignee name/surname to
     * filter their "Men bergan" (tasks I assigned) list by.
     */
    private function promptGivenSearch(Staff $staff, string $cb, int $chat, int $mid): void {
        $this->requirePermission($staff, Permission::TaskCreate);
        $this->telegram->answerCallbackQuery($cb);
        $this->clean($chat,$mid);
        $conversation = $this->conversations->getOrCreate($staff,$chat);
        $this->conversations->setState($conversation, TelegramConversationState::WAITING_TASK_MANAGEMENT_ASSIGNEE_SEARCH);
        $this->telegram->sendMessage(
            $chat,
            "🔍 <b>Bajaruvchi bo‘yicha qidirish</b>\n\nXodimning ismi yoki familiyasini yozing:",
            ['inline_keyboard'=>[[['text'=>'⬅️ Bekor qilish','callback_data'=>'tm:list:given:1']]]],
            'HTML',
        );
    }

    /**
     * Paginate through the assignee-name search results using the term
     * stored in the conversation context by promptGivenSearch()/handleInput().
     */
    private function listGivenSearch(Staff $staff, string|int $page, string $cb, int $chat, int $mid): void {
        $this->requirePermission($staff, Permission::TaskCreate);
        $conversation = $this->conversations->getOrCreate($staff,$chat);
        $term = trim((string) ($conversation->context['given_search_term'] ?? ''));
        $this->telegram->answerCallbackQuery($cb);
        $this->clean($chat,$mid);

        if ($term === '') {
            $this->telegram->sendMessage($chat,'🔍 Qidiruv muddati tugagan. Qaytadan qidiring.',['inline_keyboard'=>[
                [['text'=>'🔍 Qidirish','callback_data'=>'tm:search:given']],
                [['text'=>'⬅️ Menyu','callback_data'=>'tm:home']],
            ]]);
            return;
        }

        $this->renderGivenSearchResults($staff,$chat,$term,$page);
    }

    private function renderGivenSearchResults(Staff $staff, int $chat, string $term, string|int $page): void {
        $query = Task::query()
            ->where('assignor_id', $staff->id)
            ->where('status', '!=', TaskStatus::CLOSED->value)
            ->whereHas('assignee', function ($q) use ($term) {
                $q->where('full_name', 'like', '%'.$term.'%');
            });

        $perPage = 8;
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $resolvedPage = match ((string) $page) {
            'first' => 1,
            'last' => $lastPage,
            default => min(max(1, (int) $page), $lastPage),
        };
        $tasks = $query->orderByRaw('deadline IS NULL, deadline')->paginate($perPage,['*'],'page',$resolvedPage);
        $header = '🔍 <b>"'.e($term).'" bo‘yicha natijalar</b>';

        if ($tasks->isEmpty()) {
            $this->telegram->sendMessage($chat,"{$header}\n\nBunday bajaruvchiga biriktirilgan vazifa topilmadi.",['inline_keyboard'=>[
                [['text'=>'🔍 Qayta qidirish','callback_data'=>'tm:search:given']],
                [['text'=>'↩️ Barchasi','callback_data'=>'tm:list:given:1']],
                [['text'=>'⬅️ Menyu','callback_data'=>'tm:home']],
            ]],'HTML');
            return;
        }

        $kb=[];
        foreach($tasks as $task){
            $title=mb_strimwidth($task->title,0,22,'…');
            $deadline=$task->deadline ? TashkentDateTime::short($task->deadline) : '—';
            $assignee=mb_strimwidth($task->assignee?->full_name?:'—',0,16,'…');
            $kb[]=[['text'=>"{$task->task_number} · {$assignee} · {$title} · {$deadline}",'callback_data'=>"tm:view:{$task->id}"]];
        }
        $nav=[];
        if($tasks->currentPage()>1) $nav[]=['text'=>'⏮','callback_data'=>'tm:list:given_search:first'];
        if($tasks->currentPage()>1) $nav[]=['text'=>'⬅️','callback_data'=>'tm:list:given_search:'.($tasks->currentPage()-1)];
        $nav[]=['text'=>$tasks->currentPage().'/'.$tasks->lastPage(),'callback_data'=>'tm:noop'];
        if($tasks->hasMorePages()) $nav[]=['text'=>'➡️','callback_data'=>'tm:list:given_search:'.($tasks->currentPage()+1)];
        if($tasks->currentPage()<$tasks->lastPage()) $nav[]=['text'=>'⏭','callback_data'=>'tm:list:given_search:last'];
        $kb[]=$nav;
        $kb[]=[['text'=>'🔍 Qayta qidirish','callback_data'=>'tm:search:given'],['text'=>'↩️ Barchasi','callback_data'=>'tm:list:given:1']];
        $kb[]=[['text'=>'⬅️ Menyu','callback_data'=>'tm:home']];
        $this->telegram->sendMessage($chat,"{$header}\n\nVazifani tanlang:",['inline_keyboard'=>$kb],'HTML');
    }

    private function view(Staff $staff,int $id,string $cb,int $chat,int $mid): void {
        $this->requirePermission($staff, Permission::TaskView);
        $task=$this->service->taskForViewer($id,$staff); if(!$task)throw new DomainException('Vazifa topilmadi yoki sizga tegishli emas.');
        $this->telegram->answerCallbackQuery($cb);
        $text="📋 <b>Vazifa</b>\n\n🔢 <b>Raqam:</b> {$task->task_number}\n📌 <b>Vazifa:</b> ".e($task->title)."\n📝 <b>Tavsif:</b> ".e($task->description?:'—')."\n👤 <b>Bajaruvchi:</b> ".e($task->assignee?->full_name?:'—')."\n👤 <b>Beruvchi:</b> ".e($task->assignor?->full_name?:'—')."\n📊 <b>Holat:</b> {$task->status->label()}\n📅 <b>Muddat:</b> ".TashkentDateTime::format($task->deadline);
        $kb=[]; if($this->has($staff,Permission::TaskView)){ $row=[['text'=>'💬 Izohlar','callback_data'=>"tm:comments:$id"]]; if($this->has($staff,Permission::TaskCommentCreate))$row[]=['text'=>'➕ Izoh','callback_data'=>"tm:comment:add:$id"]; $kb[]=$row; } if($this->has($staff,Permission::TaskLogView))$kb[]=[['text'=>'📜 Tarix','callback_data'=>"tm:history:$id"]];
        if($task->assignor_id===$staff->id){
            if($this->has($staff,Permission::TaskUpdate)){$kb[]=[['text'=>'✏️ Nomi','callback_data'=>"tm:edit:title:$id"],['text'=>'📝 Tavsif','callback_data'=>"tm:edit:description:$id"]];$kb[]=[['text'=>'📅 Muddat','callback_data'=>"tm:deadline:$id"]];$kb[]=[['text'=>'📨 Eslatma','callback_data'=>"tm:remind:$id"]];}
            if($this->has($staff,Permission::TaskReassign))$kb[]=[['text'=>'🔄 Qayta biriktirish','callback_data'=>"tm:reassign:$id:1"]];
            if($this->has($staff,Permission::TaskCancel))$kb[]=[['text'=>'❌ Bekor qilish','callback_data'=>"tm:cancel:$id"]];
        }
        // Assignee actions are intentionally status-based. A staff member who merely
        // received a task must never get assignor-only controls such as cancellation.
        if ($task->assignee_id === $staff->id) {
            $statusAction = match ($task->status) {
                TaskStatus::ASSIGNED => $this->has($staff, Permission::TaskAccept)
                    ? ['text' => '✅ Qabul qilish', 'callback_data' => "tm:status:$id:accepted"]
                    : null,
                TaskStatus::ACCEPTED => $this->has($staff, Permission::TaskStart)
                    ? ['text' => '▶️ Ishni boshlash', 'callback_data' => "tm:status:$id:in_progress"]
                    : null,
                TaskStatus::IN_PROGRESS => $this->has($staff, Permission::TaskSubmit)
                    ? ['text' => '✅ Bajarildi', 'callback_data' => "tm:status:$id:awaiting_acceptance"]
                    : null,
                default => null,
            };

            if ($statusAction !== null) {
                $kb[] = [$statusAction];
            }

            if ($task->deadline && in_array($task->status,[TaskStatus::ASSIGNED,TaskStatus::ACCEPTED,TaskStatus::IN_PROGRESS],true)) {
                $kb[] = [['text'=>'⏳ Muddatni kechiktirish','callback_data'=>"postpone:task:$id"]];
            }
        }
        $kb[]=[['text'=>'⬅️ Menyu','callback_data'=>'tm:home']];
        try {
            $this->telegram->sendMessage($chat,$text,['inline_keyboard'=>$kb],'HTML');
            // Only clean the task-selector after the task card was successfully sent.
            $this->clean($chat,$mid);
        } catch (Throwable $e) {
            Log::error('Failed to open task management card.', [
                'task_id' => $task->id,
                'staff_id' => $staff->id,
                'chat_id' => $chat,
                'message_id' => $mid,
                'error' => $e->getMessage(),
            ]);
            $this->telegram->sendMessage($chat,'❌ Vazifa kartasini ochishda xatolik yuz berdi. Qayta urinib ko‘ring.');
        }
    }

    private function statusAction(Staff $staff, int $id, TaskStatus $newStatus, string $cb, int $chat, int $mid): void
    {
        $this->requirePermission($staff, Permission::TaskView);

        $task = Task::query()->find($id);
        if (! $task || $task->assignee_id !== $staff->id) {
            throw new DomainException('Bu vazifa sizga biriktirilmagan.');
        }

        $allowed = match ($newStatus) {
            TaskStatus::ACCEPTED => $task->status === TaskStatus::ASSIGNED && $this->has($staff, Permission::TaskAccept),
            TaskStatus::IN_PROGRESS => $task->status === TaskStatus::ACCEPTED && $this->has($staff, Permission::TaskStart),
            TaskStatus::AWAITING_ACCEPTANCE => $task->status === TaskStatus::IN_PROGRESS && $this->has($staff, Permission::TaskSubmit),
            default => false,
        };

        if (! $allowed) {
            throw new DomainException('Bu amal hozir bajarilishi mumkin emas yoki ruxsatingiz yo‘q.');
        }

        // The status callback owns the existing transition/business rules. We only mark
        // this invocation as a management-card action so the card is preserved.
        $this->taskStatus->handle(
            staff: $staff,
            taskId: $id,
            newStatus: $newStatus,
            callbackQueryId: $cb,
            chatId: $chat,
            messageId: $mid,
            preserveMessage: true,
        );
    }

    private function history(Staff $staff,int $id,string $cb,int $chat,int $mid): void {$this->requirePermission($staff, Permission::TaskLogView);$task=$this->service->taskForViewer($id,$staff);if(!$task)throw new DomainException('Vazifa topilmadi.');$this->telegram->answerCallbackQuery($cb);$this->clean($chat,$mid);$logs=$task->logs()->with('actor')->latest('created_at')->limit(15)->get()->reverse();$lines=$logs->map(fn($l)=>'• '.$l->created_at->format('d.m H:i').' — '.e($l->actor?->full_name?:'Tizim').' — '.e($l->message ?: ($l->event_type?->value ?? 'amal')))->implode("\n");$this->telegram->sendMessage($chat,"📜 <b>{$task->task_number} tarixi</b>\n\n".($lines?:'Tarix mavjud emas.'),['inline_keyboard'=>[[['text'=>'⬅️ Vazifaga qaytish','callback_data'=>"tm:view:$id"]]]],'HTML');}
    private function comments(Staff $staff,int $id,string $cb,int $chat,int $mid): void {$this->requirePermission($staff, Permission::TaskView);$task=$this->service->taskForViewer($id,$staff);if(!$task)throw new DomainException('Vazifa topilmadi.');$this->telegram->answerCallbackQuery($cb);$this->clean($chat,$mid);$comments=$task->comments()->with('staff')->latest()->limit(10)->get()->reverse();$body=$comments->map(fn($c)=>"👤 <b>".e($c->staff?->full_name?:'—')."</b> · ".$c->created_at->format('d.m H:i')."\n".e($c->body))->implode("\n\n");$kb=[]; if($this->has($staff,Permission::TaskCommentCreate))$kb[]=[['text'=>'➕ Izoh qo‘shish','callback_data'=>"tm:comment:add:$id"]]; $kb[]=[['text'=>'⬅️ Vazifaga qaytish','callback_data'=>"tm:view:$id"]]; $this->telegram->sendMessage($chat,"💬 <b>{$task->task_number} izohlari</b>\n\n".($body?:'Hali izoh yo‘q.'),['inline_keyboard'=>$kb],'HTML');}
    private function waitInput(Staff $staff,int $chat,string $cb,int $mid,TelegramConversationState $state,array $context):void{ if($state===TelegramConversationState::WAITING_TASK_MANAGEMENT_COMMENT)$this->requirePermission($staff,Permission::TaskCommentCreate); else $this->requirePermission($staff,Permission::TaskUpdate);$this->telegram->answerCallbackQuery($cb);$this->clean($chat,$mid);$conv=$this->conversations->getOrCreate($staff,$chat);$conv->update(['state'=>$state->value,'context'=>$context,'last_activity_at'=>now()]);$text=$state===TelegramConversationState::WAITING_TASK_MANAGEMENT_COMMENT?'💬 Izohingizni yuboring. Matn, voice, video yoki boshqa media yuborishingiz mumkin.':'✏️ Yangi qiymatni yuboring. Matn, voice yoki video yuborishingiz mumkin.';$this->telegram->sendMessage($chat,$text);}
    private function deadlineMenu(Staff $staff,int $id,string $cb,int $chat,int $mid):void{$this->requirePermission($staff,Permission::TaskUpdate);$task=$this->service->taskForViewer($id,$staff);if(!$task||$task->assignor_id!==$staff->id)throw new DomainException('Ruxsat yo‘q.');$this->telegram->answerCallbackQuery($cb);$this->clean($chat,$mid);$rows=[[60,120],[240,480],[1440,2880],[4320,10080]];$labels=[60=>'⏱ +1 soat',120=>'⏱ +2 soat',240=>'⏱ +4 soat',480=>'⏱ +8 soat',1440=>'📅 +1 kun',2880=>'📅 +2 kun',4320=>'📅 +3 kun',10080=>'📅 +7 kun'];$kb=[];foreach($rows as $row){$kb[]=array_map(fn($m)=>['text'=>$labels[$m],'callback_data'=>"tm:deadline:set:$id:$m"],$row);}$kb[]=[['text'=>'⬅️ Vazifaga qaytish','callback_data'=>"tm:view:$id"]];$this->telegram->sendMessage($chat,'📅 <b>Yangi muddat qo‘shing</b>',['inline_keyboard'=>$kb],'HTML');}
    private function setDeadline(Staff $staff,int $id,int $min,string $cb,int $chat,int $mid):void{
        $this->requirePermission($staff,Permission::TaskUpdate);
        $task=$this->service->taskForViewer($id,$staff);
        if(!$task)throw new DomainException('Vazifa topilmadi.');

        $previousDeadline = $task->deadline?->copy();
        $task=$this->service->changeDeadline($task,$staff,$min);

        $this->telegram->answerCallbackQuery($cb,'Muddat o‘zgartirildi.');
        $this->clean($chat,$mid);

        $this->service->notifyAssigneeOfChange(
            $task,
            '📅 <b>Vazifa muddati o‘zgartirildi</b>',
            '📅 <b>Oldingi muddat:</b> '.TashkentDateTime::format($previousDeadline)."\n"
            .'📅 <b>Yangi muddat:</b> '.TashkentDateTime::format($task->deadline),
        );
        $this->telegram->sendMessage($chat,'✅ Muddat o‘zgartirildi.');
    }
    private function reassignList(Staff $staff,int $id,string|int $page,string $cb,int $chat,int $mid):void{$this->requirePermission($staff,Permission::TaskReassign);$task=$this->service->taskForViewer($id,$staff);if(!$task||$task->assignor_id!==$staff->id)throw new DomainException('Ruxsat yo‘q.');$this->telegram->answerCallbackQuery($cb);$this->clean($chat,$mid);$peopleQuery=Staff::query()->where('status','active')->whereNotNull('telegram_chat_id')->where('id','!=',$task->assignee_id);
        $perPage=8;
        $lastPage=max(1,(int)ceil((clone $peopleQuery)->count()/$perPage));
        $resolvedPage=match((string)$page){'first'=>1,'last'=>$lastPage,default=>min(max(1,(int)$page),$lastPage)};
        $people=$peopleQuery->orderBy('full_name')->paginate($perPage,['*'],'page',$resolvedPage);$kb=[];foreach($people as $p)$kb[]=[['text'=>$p->full_name,'callback_data'=>"tm:reassign:set:$id:$p->id"]];$nav=[];
        if($people->currentPage()>1)$nav[]=['text'=>'⏮','callback_data'=>"tm:reassign:$id:first"];
        if($people->currentPage()>1)$nav[]=['text'=>'⬅️','callback_data'=>"tm:reassign:$id:".($people->currentPage()-1)];
        $nav[]=['text'=>$people->currentPage().'/'.$people->lastPage(),'callback_data'=>'tm:noop'];
        if($people->hasMorePages())$nav[]=['text'=>'➡️','callback_data'=>"tm:reassign:$id:".($people->currentPage()+1)];
        if($people->currentPage()<$people->lastPage())$nav[]=['text'=>'⏭','callback_data'=>"tm:reassign:$id:last"];
        if($nav)$kb[]=$nav;$kb[]=[['text'=>'⬅️ Vazifaga qaytish','callback_data'=>"tm:view:$id"]];$this->telegram->sendMessage($chat,'🔄 <b>Yangi bajaruvchini tanlang</b>',['inline_keyboard'=>$kb],'HTML');}
    private function reassign(Staff $staff,int $id,int $new,string $cb,int $chat,int $mid):void{$this->requirePermission($staff,Permission::TaskReassign);$task=$this->service->taskForViewer($id,$staff);$person=Staff::find($new);if(!$task||!$person)throw new DomainException('Vazifa yoki xodim topilmadi.');$result=$this->service->reassign($task,$staff,$person);$this->telegram->answerCallbackQuery($cb,'Vazifa qayta biriktirildi.');$this->clean($chat,$mid);if($result['old_assignee']?->telegram_chat_id)$this->telegram->sendMessage(
            $result['old_assignee']->telegram_chat_id,
            "🔄 <b>Vazifa boshqa xodimga biriktirildi</b>\n\n" . $this->service->taskDetailsText($result['task']),
            parseMode:'HTML'
        );$this->service->cleanupTaskNotificationMessages($task);$this->service->notifyNewAssignee($result['task']);$this->telegram->sendMessage($chat,'✅ Vazifa qayta biriktirildi.');}
    private function cancelConfirm(Staff $staff,int $id,string $cb,int $chat,int $mid):void{
        $this->requirePermission($staff,Permission::TaskCancel);
        $task=$this->service->taskForViewer($id,$staff);
        if(!$task || $task->assignor_id !== $staff->id) throw new DomainException('Faqat vazifa beruvchisi vazifani bekor qilishi mumkin.');
        $this->telegram->answerCallbackQuery($cb);
        // Keep the management card. Replace its keyboard with confirmation controls.
        $this->telegram->editMessageReplyMarkup($chat,$mid,[ 'inline_keyboard'=>[[['text'=>'❌ Ha, bekor qilish','callback_data'=>"tm:cancel:yes:$id"],['text'=>'⬅️ Yo‘q','callback_data'=>"tm:view:$id"]]]]);
    }
    private function cancel(Staff $staff,int $id,string $cb,int $chat,int $mid):void{
        $this->requirePermission($staff,Permission::TaskCancel);
        $task=$this->service->taskForViewer($id,$staff);
        if(!$task || $task->assignor_id !== $staff->id) throw new DomainException('Faqat vazifa beruvchisi vazifani bekor qilishi mumkin.');
        $task=$this->service->cancel($task,$staff);
        $this->telegram->answerCallbackQuery($cb,'Vazifa bekor qilindi.');
        // The card remains; only its action buttons are removed.
        $this->telegram->editMessageReplyMarkup($chat,$mid,null);
        // Notify before cleanup: for an unaccepted GROUP task, the recipient
        // list lives only in the TaskTelegramMessage rows that cleanup deletes.
        $this->service->notifyAssigneeOfChange($task,'❌ <b>Vazifa bekor qilindi</b>');
        $this->service->cleanupTaskNotificationMessages($task);
    }
    private function remind(Staff $staff,int $id,string $cb,int $chat,int $mid):void{$this->requirePermission($staff,Permission::TaskUpdate);$task=$this->service->taskForViewer($id,$staff);if(!$task)throw new DomainException('Vazifa topilmadi.');$this->service->sendReminder($task,$staff);$this->telegram->answerCallbackQuery($cb,'Eslatma yuborildi.');$this->clean($chat,$mid);$this->telegram->sendMessage($chat,'📨 Eslatma yuborildi.');}
    private function clean(int $chat,int $mid):void{try{$this->telegram->deleteMessage($chat,$mid);}catch(Throwable $e){Log::debug('Task management cleanup failed',['message_id'=>$mid]);}}
}
