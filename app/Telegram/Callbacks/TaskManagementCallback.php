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
            if (preg_match('/^tm:list:([a-z_]+):(first|last|\d+)$/', $data, $m) && $this->isKnownList($m[1])) { $this->list($staff,$m[1],$m[2],$callbackId,$chatId,$messageId); return; }
            if (preg_match('/^tm:menu:(my|given)$/', $data, $m)) { $this->roleMenu($staff,$m[1],$callbackId,$chatId,$messageId); return; }
            if ($data === 'tm:noop') { $this->telegram->answerCallbackQuery($callbackId); return; }
            if (preg_match('/^tm:search:given:pick:(\d+):(first|last|\d+)$/', $data, $m)) { $this->searchGivenTasksForStaff($staff,(int)$m[1],$m[2],$callbackId,$chatId,$messageId); return; }
            if (preg_match('/^tm:search:given:(first|last|\d+)$/', $data, $m)) { $this->searchGivenStaffList($staff,$m[1],$callbackId,$chatId,$messageId); return; }
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

    /**
     * Lists the management menu can show, grouped by which side of the task the
     * staff member is on. A single list never mixes the two sides: a button is
     * either about work this person has to do, or about work they handed out.
     * Order inside a group is "needs my attention first".
     */
    private const ROLE_BUCKETS = [
        'my' => ['my_new','my_progress','my_overdue','my_due','my_submitted','my','my_closed'],
        'given' => ['given_review','given_new','given_progress','given_overdue','given_due','given','given_closed'],
    ];

    /**
     * Callback data from cards that are already sitting in people's chat
     * history. The old buttons silently mixed both sides; they now resolve to
     * the assignee list, which is what the person tapping them expects.
     */
    private const LEGACY_LIST_TYPES = [
        'progress' => 'my_progress',
        'due' => 'my_due',
        'overdue' => 'my_overdue',
        'closed' => 'my_closed',
    ];

    private function isKnownList(string $type): bool
    {
        return isset(self::LEGACY_LIST_TYPES[$type])
            || in_array($type, array_merge(self::ROLE_BUCKETS['my'], self::ROLE_BUCKETS['given']), true);
    }

    private function roleOf(string $type): string
    {
        return str_starts_with($type, 'given') ? 'given' : 'my';
    }

    /**
     * Button caption for one list. Each caption has to answer "what do I get if
     * I tap this?" on its own, without the user reading the menu header.
     */
    private function bucketButton(string $type): string
    {
        return match ($type) {
            'my_new' => '🆕 Qabul qilishim kerak',
            'my_progress' => '⏳ Men bajarayotganlarim',
            'my_submitted' => '📨 Topshirdim, tasdiq kutyapman',
            'my_overdue' => '🔴 Muddati o‘tgan — men bajaraman',
            'my_due' => '⏰ 24 soat qoldi — men bajaraman',
            'my' => '📋 Menga berilgan hamma vazifa',
            'my_closed' => '📁 Arxiv — menga berilganlar',
            'given_review' => '🔍 Men tekshirishim kerak',
            'given_new' => '🆕 Xodim hali qabul qilmagan',
            'given_progress' => '⏳ Xodimlar bajarmoqda',
            'given_overdue' => '🔴 Muddati o‘tgan — xodimlarda',
            'given_due' => '⏰ 24 soat qoldi — xodimlarda',
            'given' => '📋 Men bergan hamma vazifa',
            'given_closed' => '📁 Arxiv — men berganlarim',
        };
    }

    /** One sentence under the list title, so the filter is never guesswork. */
    private function bucketDescription(string $type): string
    {
        return match ($type) {
            'my_new' => 'Sizga berilgan, siz hali qabul qilmagan vazifalar.',
            'my_progress' => 'Siz qabul qilgan va hozir bajarayotgan vazifalaringiz.',
            'my_submitted' => 'Siz bajardim deb topshirgan, vazifa beruvchining tasdig‘ini kutayotgan vazifalar.',
            'my_overdue' => 'Siz bajarishingiz kerak bo‘lgan, muddati allaqachon o‘tib ketgan vazifalar.',
            'my_due' => 'Siz bajarishingiz kerak bo‘lgan, muddatiga 24 soatdan kam qolgan vazifalar.',
            'my' => 'Sizga biriktirilgan, hali yopilmagan barcha vazifalar.',
            'my_closed' => 'Sizga biriktirilgan, yopilgan yoki bekor qilingan vazifalar.',
            'given_review' => 'Xodim bajardim deb topshirgan, sizning tasdig‘ingizni kutayotgan vazifalar.',
            'given_new' => 'Siz bergan, xodim hali qabul qilmagan vazifalar.',
            'given_progress' => 'Siz bergan, xodim hozir bajarayotgan vazifalar.',
            'given_overdue' => 'Siz bergan, muddati o‘tib ketgan vazifalar.',
            'given_due' => 'Siz bergan, muddatiga 24 soatdan kam qolgan vazifalar.',
            'given' => 'Siz bergan, hali yopilmagan barcha vazifalar.',
            'given_closed' => 'Siz bergan, yopilgan yoki bekor qilingan vazifalar.',
        };
    }

    /**
     * The query behind one bucket. The count on a menu button and the list it
     * opens both come from here, so a button can never promise a number the
     * list does not show.
     */
    private function listQuery(string $type, Staff $staff)
    {
        $open = [
            TaskStatus::CREATED->value,
            TaskStatus::ASSIGNED->value,
            TaskStatus::ACCEPTED->value,
            TaskStatus::IN_PROGRESS->value,
            TaskStatus::AWAITING_ACCEPTANCE->value,
            TaskStatus::COMPLETION_APPROVED->value,
            TaskStatus::RETURNED->value,
        ];
        $archived = [TaskStatus::CLOSED->value, TaskStatus::CANCELLED->value];
        $working = [TaskStatus::ACCEPTED->value, TaskStatus::IN_PROGRESS->value, TaskStatus::RETURNED->value];
        $review = [TaskStatus::AWAITING_ACCEPTANCE->value, TaskStatus::COMPLETION_APPROVED->value];

        $query = Task::query()->where(
            $this->roleOf($type) === 'given' ? 'assignor_id' : 'assignee_id',
            $staff->id,
        );

        return match ($type) {
            'my', 'given' => $query->whereIn('status', $open),
            'my_new' => $query->where('status', TaskStatus::ASSIGNED->value),
            'given_new' => $query->whereIn('status', [TaskStatus::CREATED->value, TaskStatus::ASSIGNED->value]),
            'my_progress', 'given_progress' => $query->whereIn('status', $working),
            'my_submitted', 'given_review' => $query->whereIn('status', $review),
            'my_due', 'given_due' => $query->whereIn('status', $open)->whereNotNull('deadline')->whereBetween('deadline', [now(), now()->addDay()]),
            'my_overdue', 'given_overdue' => $query->whereIn('status', $open)->whereNotNull('deadline')->where('deadline', '<', now()),
            'my_closed', 'given_closed' => $query->whereIn('status', $archived),
        };
    }

    private function countFor(string $type, Staff $staff): int
    {
        return $this->listQuery($type, $staff)->count();
    }

    public function home(Staff $staff,string $cb,int $chat,int $mid): void { $this->telegram->answerCallbackQuery($cb); $this->clean($chat,$mid); $this->sendHome($chat,$staff); }

    /**
     * Root menu. It asks one question only: whose tasks do you want to see, the
     * ones given to you or the ones you gave out? Staff who cannot assign tasks
     * have only one answer, so they skip this level entirely.
     */
    public function sendHome(int $chat, ?Staff $staff = null): void {
        if (! $staff) { $this->telegram->sendMessage($chat, '❌ Foydalanuvchi aniqlanmadi. /start buyrug‘ini yuboring.'); return; }
        if (! $this->has($staff, Permission::TaskView)) { $this->telegram->sendMessage($chat, '❌ Vazifalarni ko‘rish uchun ruxsatingiz yo‘q.'); return; }
        if (! $this->has($staff, Permission::TaskCreate)) { $this->sendRoleMenu($chat, $staff, 'my', false); return; }
        $mine = $this->countFor('my', $staff);
        $given = $this->countFor('given', $staff);
        $this->telegram->sendMessage($chat,
            "📋 <b>Vazifalarni boshqarish</b>\n\n"
            ."📥 <b>Menga berilgan</b> — men bajarishim kerak bo‘lgan vazifalar.\n"
            ."📤 <b>Men bergan</b> — men boshqa xodimlarga bergan vazifalar.",
            ['inline_keyboard'=>[
                [['text'=>"📥 Menga berilgan vazifalar ({$mine})",'callback_data'=>'tm:menu:my']],
                [['text'=>"📤 Men bergan vazifalar ({$given})",'callback_data'=>'tm:menu:given']],
            ]],'HTML');
    }

    private function roleMenu(Staff $staff, string $role, string $cb, int $chat, int $mid): void {
        $this->requirePermission($staff, Permission::TaskView);
        if ($role === 'given') { $this->requirePermission($staff, Permission::TaskCreate); }
        $this->telegram->answerCallbackQuery($cb);
        $this->clean($chat,$mid);
        $this->sendRoleMenu($chat, $staff, $role, $this->has($staff, Permission::TaskCreate));
    }

    /**
     * Second level: the filters for one side. Every button carries its own live
     * count, so the user sees what a list holds before opening it.
     */
    private function sendRoleMenu(int $chat, Staff $staff, string $role, bool $withBack): void {
        $kb = [];
        foreach (self::ROLE_BUCKETS[$role] as $type) {
            $kb[] = [['text'=>$this->bucketButton($type).' ('.$this->countFor($type,$staff).')','callback_data'=>"tm:list:{$type}:1"]];
        }
        if ($role === 'given') { $kb[] = [['text'=>'👤 Bitta xodimning vazifalari','callback_data'=>'tm:search:given:1']]; }
        if ($withBack) { $kb[] = [['text'=>'⬅️ Bosh menyu','callback_data'=>'tm:home']]; }
        $header = $role === 'given'
            ? "📤 <b>Men bergan vazifalar</b>\n\nMen boshqa xodimlarga bergan vazifalar. Qaysi ro‘yxatni ochamiz?"
            : "📥 <b>Menga berilgan vazifalar</b>\n\nMen bajarishim kerak bo‘lgan vazifalar. Qaysi ro‘yxatni ochamiz?";
        $this->telegram->sendMessage($chat,$header,['inline_keyboard'=>$kb],'HTML');
    }

    private function list(Staff $staff,string $type,string|int $page,string $cb,int $chat,int $mid): void {
        $this->requirePermission($staff, Permission::TaskView);
        $type = self::LEGACY_LIST_TYPES[$type] ?? $type;
        $role = $this->roleOf($type);
        // Only staff who can hand out tasks have an assignor side at all.
        if ($role === 'given') { $this->requirePermission($staff, Permission::TaskCreate); }
        $this->telegram->answerCallbackQuery($cb); $this->clean($chat,$mid);

        $query = $this->listQuery($type, $staff);
        $perPage = 8;
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $resolvedPage = match ((string) $page) {
            'first' => 1,
            'last' => $lastPage,
            default => min(max(1, (int) $page), $lastPage),
        };
        $tasks = $query->orderByRaw('deadline IS NULL, deadline')->paginate($perPage,['*'],'page',$resolvedPage);

        // The heading repeats the button caption and spells out the filter, so
        // the list never leaves the user wondering which tasks they are looking at.
        $heading = '<b>'.$this->bucketButton($type).'</b>'."\n".'<i>'.$this->bucketDescription($type).'</i>';
        $back = [['text'=>$role === 'given' ? '⬅️ Men bergan vazifalar' : '⬅️ Menga berilgan vazifalar','callback_data'=>"tm:menu:{$role}"]];

        if($tasks->isEmpty()){
            $this->telegram->sendMessage($chat,$heading."\n\nHozircha bunday vazifa yo‘q.",['inline_keyboard'=>[$back]],'HTML');
            return;
        }

        $kb=[];
        foreach($tasks as $task){
            $title=mb_strimwidth($task->title,0,32,'…');
            $deadline=$task->deadline ? TashkentDateTime::short($task->deadline) : '—';
            $kb[]=[['text'=>"{$task->task_number} · {$title} · {$deadline}",'callback_data'=>"tm:view:{$task->id}"]];
        }
        $nav=[];
        if($tasks->currentPage()>1) $nav[]=['text'=>'⏮','callback_data'=>"tm:list:{$type}:first"];
        if($tasks->currentPage()>1) $nav[]=['text'=>'⬅️','callback_data'=>"tm:list:{$type}:".($tasks->currentPage()-1)];
        $nav[]=['text'=>$tasks->currentPage().'/'.$tasks->lastPage(),'callback_data'=>'tm:noop'];
        if($tasks->hasMorePages()) $nav[]=['text'=>'➡️','callback_data'=>"tm:list:{$type}:".($tasks->currentPage()+1)];
        if($tasks->currentPage()<$tasks->lastPage()) $nav[]=['text'=>'⏭','callback_data'=>"tm:list:{$type}:last"];
        if(count($nav)>1) $kb[]=$nav;
        if ($role === 'given') {
            $kb[]=[['text'=>'👤 Bitta xodimning vazifalari','callback_data'=>'tm:search:given:1']];
        }
        $kb[]=$back;
        $this->telegram->sendMessage($chat,$heading."\n\n👇 Batafsil ko‘rish uchun vazifani tanlang ({$total} ta):",['inline_keyboard'=>$kb],'HTML');
    }

    /**
     * Show the assignor (task creator) a paginated list of staff they have
     * assigned at least one (non-closed) task to — tapping one drills into
     * that person's tasks. Replaces free-text search with pure buttons.
     */
    private function searchGivenStaffList(Staff $staff, string|int $page, string $cb, int $chat, int $mid): void {
        $this->requirePermission($staff, Permission::TaskCreate);
        $this->telegram->answerCallbackQuery($cb);
        $this->clean($chat,$mid);

        $assigneeIds = Task::query()
            ->where('assignor_id', $staff->id)
            ->whereNotIn('status', [TaskStatus::CLOSED->value, TaskStatus::CANCELLED->value])
            ->whereNotNull('assignee_id')
            ->distinct()
            ->pluck('assignee_id');

        $peopleQuery = Staff::query()
            ->whereIn('id', $assigneeIds)
            ->orderBy('full_name');

        $perPage = 8;
        $lastPage = max(1, (int) ceil((clone $peopleQuery)->count() / $perPage));
        $resolvedPage = match ((string) $page) {
            'first' => 1,
            'last' => $lastPage,
            default => min(max(1, (int) $page), $lastPage),
        };
        $people = $peopleQuery->paginate($perPage, ['*'], 'page', $resolvedPage);

        if ($people->isEmpty()) {
            $this->telegram->sendMessage($chat,'👤 <b>Bitta xodimning vazifalari</b>'."\n\n".'Hozircha siz hech kimga vazifa bermagansiz.',['inline_keyboard'=>[
                [['text'=>'⬅️ Men bergan vazifalar','callback_data'=>'tm:menu:given']],
            ]],'HTML');
            return;
        }

        $kb=[];
        foreach($people as $p){
            $kb[]=[['text'=>$p->full_name,'callback_data'=>"tm:search:given:pick:{$p->id}:1"]];
        }
        $nav=[];
        if($people->currentPage()>1) $nav[]=['text'=>'⏮','callback_data'=>'tm:search:given:first'];
        if($people->currentPage()>1) $nav[]=['text'=>'⬅️','callback_data'=>'tm:search:given:'.($people->currentPage()-1)];
        $nav[]=['text'=>$people->currentPage().'/'.$people->lastPage(),'callback_data'=>'tm:noop'];
        if($people->hasMorePages()) $nav[]=['text'=>'➡️','callback_data'=>'tm:search:given:'.($people->currentPage()+1)];
        if($people->currentPage()<$people->lastPage()) $nav[]=['text'=>'⏭','callback_data'=>'tm:search:given:last'];
        if(count($nav)>1) $kb[]=$nav;
        $kb[]=[['text'=>'⬅️ Men bergan vazifalar','callback_data'=>'tm:menu:given']];
        $this->telegram->sendMessage($chat,'👤 <b>Bitta xodimning vazifalari</b>'."\n\n".'<i>Siz bergan vazifalarni xodim bo‘yicha ko‘rish.</i>'."\n\n".'Xodimni tanlang:',['inline_keyboard'=>$kb],'HTML');
    }

    /**
     * Show every (non-closed) task the assignor gave to one specific
     * assignee, selected from searchGivenStaffList().
     */
    private function searchGivenTasksForStaff(Staff $staff, int $assigneeId, string|int $page, string $cb, int $chat, int $mid): void {
        $this->requirePermission($staff, Permission::TaskCreate);
        $this->telegram->answerCallbackQuery($cb);
        $this->clean($chat,$mid);

        $assignee = Staff::find($assigneeId);
        if (!$assignee) throw new DomainException('Xodim topilmadi.');

        $query = Task::query()
            ->where('assignor_id', $staff->id)
            ->where('assignee_id', $assigneeId)
            ->whereNotIn('status', [TaskStatus::CLOSED->value, TaskStatus::CANCELLED->value]);

        $perPage = 8;
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $resolvedPage = match ((string) $page) {
            'first' => 1,
            'last' => $lastPage,
            default => min(max(1, (int) $page), $lastPage),
        };
        $tasks = $query->orderByRaw('deadline IS NULL, deadline')->paginate($perPage,['*'],'page',$resolvedPage);
        $header = '👤 <b>'.e($assignee->full_name).'</b>'."\n".'<i>Siz shu xodimga bergan, hali yopilmagan vazifalar.</i>';

        if ($tasks->isEmpty()) {
            $this->telegram->sendMessage($chat,"{$header}\n\nHozircha bunday vazifa yo‘q.",['inline_keyboard'=>[
                [['text'=>'⬅️ Boshqa xodimni tanlash','callback_data'=>'tm:search:given:1']],
                [['text'=>'⬅️ Men bergan vazifalar','callback_data'=>'tm:menu:given']],
            ]],'HTML');
            return;
        }

        $kb=[];
        foreach($tasks as $task){
            $title=mb_strimwidth($task->title,0,32,'…');
            $deadline=$task->deadline ? TashkentDateTime::short($task->deadline) : '—';
            $kb[]=[['text'=>"{$task->task_number} · {$title} · {$deadline}",'callback_data'=>"tm:view:{$task->id}"]];
        }
        $nav=[];
        if($tasks->currentPage()>1) $nav[]=['text'=>'⏮','callback_data'=>"tm:search:given:pick:{$assigneeId}:first"];
        if($tasks->currentPage()>1) $nav[]=['text'=>'⬅️','callback_data'=>"tm:search:given:pick:{$assigneeId}:".($tasks->currentPage()-1)];
        $nav[]=['text'=>$tasks->currentPage().'/'.$tasks->lastPage(),'callback_data'=>'tm:noop'];
        if($tasks->hasMorePages()) $nav[]=['text'=>'➡️','callback_data'=>"tm:search:given:pick:{$assigneeId}:".($tasks->currentPage()+1)];
        if($tasks->currentPage()<$tasks->lastPage()) $nav[]=['text'=>'⏭','callback_data'=>"tm:search:given:pick:{$assigneeId}:last"];
        if(count($nav)>1) $kb[]=$nav;
        $kb[]=[['text'=>'⬅️ Boshqa xodimni tanlash','callback_data'=>'tm:search:given:1']];
        $kb[]=[['text'=>'⬅️ Men bergan vazifalar','callback_data'=>'tm:menu:given']];
        $this->telegram->sendMessage($chat,"{$header}\n\n👇 Batafsil ko‘rish uchun vazifani tanlang ({$total} ta):",['inline_keyboard'=>$kb],'HTML');
    }

    private function view(Staff $staff,int $id,string $cb,int $chat,int $mid): void {
        $this->requirePermission($staff, Permission::TaskView);
        $task=$this->service->taskForViewer($id,$staff); if(!$task)throw new DomainException('Vazifa topilmadi yoki sizga tegishli emas.');
        $this->telegram->answerCallbackQuery($cb);
        $isAssignor = $task->assignor_id === $staff->id;
        $isAssignee = $task->assignee_id === $staff->id;
        // The same card is opened by both sides and each side gets different
        // buttons. Saying the role out loud removes any doubt about why one
        // person sees "bekor qilish" and another does not.
        $roleLine = match (true) {
            $isAssignor && $isAssignee => 'Beruvchi va bajaruvchi — vazifani o‘zingizga bergansiz',
            $isAssignor => 'Beruvchi — vazifani siz bergansiz',
            $isAssignee => 'Bajaruvchi — vazifani siz bajarasiz',
            default => 'Muallif — vazifani siz yaratgansiz',
        };
        $text="📋 <b>Vazifa</b>\n\n🔢 <b>Raqam:</b> {$task->task_number}\n📌 <b>Vazifa:</b> ".e($task->title)."\n📝 <b>Tavsif:</b> ".e($task->description?:'—')."\n👤 <b>Bajaruvchi:</b> ".e($task->assignee?->full_name?:'—')."\n👤 <b>Beruvchi:</b> ".e($task->assignor?->full_name?:'—')."\n📊 <b>Holat:</b> {$task->status->label()}\n📅 <b>Muddat:</b> ".TashkentDateTime::format($task->deadline)."\n🧭 <b>Sizning rolingiz:</b> ".$roleLine;
        $kb=[]; if($this->has($staff,Permission::TaskView)){ $row=[['text'=>'💬 Izohlarni o‘qish','callback_data'=>"tm:comments:$id"]]; if($this->has($staff,Permission::TaskCommentCreate))$row[]=['text'=>'➕ Izoh yozish','callback_data'=>"tm:comment:add:$id"]; $kb[]=$row; } if($this->has($staff,Permission::TaskLogView))$kb[]=[['text'=>'📜 O‘zgarishlar tarixi','callback_data'=>"tm:history:$id"]];
        if($isAssignor){
            if($this->has($staff,Permission::TaskUpdate)){
                $kb[]=[['text'=>'✏️ Nomini o‘zgartirish','callback_data'=>"tm:edit:title:$id"]];
                $kb[]=[['text'=>'📝 Tavsifini o‘zgartirish','callback_data'=>"tm:edit:description:$id"]];
                $kb[]=[['text'=>'📅 Muddatni uzaytirish','callback_data'=>"tm:deadline:$id"]];
                $kb[]=[['text'=>'📨 Bajaruvchiga eslatma yuborish','callback_data'=>"tm:remind:$id"]];
            }
            if($this->has($staff,Permission::TaskReassign))$kb[]=[['text'=>'🔄 Boshqa xodimga biriktirish','callback_data'=>"tm:reassign:$id:1"]];
            if($this->has($staff,Permission::TaskCancel))$kb[]=[['text'=>'❌ Vazifani bekor qilish','callback_data'=>"tm:cancel:$id"]];
        }
        // Assignee actions are intentionally status-based. A staff member who merely
        // received a task must never get assignor-only controls such as cancellation.
        if ($isAssignee) {
            $statusAction = match ($task->status) {
                TaskStatus::ASSIGNED => $this->has($staff, Permission::TaskAccept)
                    ? ['text' => '✅ Vazifani qabul qilaman', 'callback_data' => "tm:status:$id:accepted"]
                    : null,
                TaskStatus::ACCEPTED => $this->has($staff, Permission::TaskStart)
                    ? ['text' => '▶️ Ishni boshladim', 'callback_data' => "tm:status:$id:in_progress"]
                    : null,
                TaskStatus::IN_PROGRESS => $this->has($staff, Permission::TaskSubmit)
                    ? ['text' => '✅ Bajardim — tasdiqqa yuboraman', 'callback_data' => "tm:status:$id:awaiting_acceptance"]
                    : null,
                default => null,
            };

            if ($statusAction !== null) {
                $kb[] = [$statusAction];
            }

            if ($task->deadline && in_array($task->status,[TaskStatus::ASSIGNED,TaskStatus::ACCEPTED,TaskStatus::IN_PROGRESS],true)) {
                // This one only asks the assignor; it never moves the deadline
                // by itself, and the caption has to say so.
                $kb[] = [['text'=>'⏳ Muddatni uzaytirishni so‘rash','callback_data'=>"postpone:task:$id"]];
            }
        }
        // Go back to the side the card was opened from, so the user lands on the
        // list they came from rather than at the top of the menu.
        $backToGiven = $isAssignor && ! $isAssignee && $this->has($staff, Permission::TaskCreate);
        $kb[]=[[
            'text'=>$backToGiven ? '⬅️ Men bergan vazifalar' : '⬅️ Menga berilgan vazifalar',
            'callback_data'=>$backToGiven ? 'tm:menu:given' : 'tm:menu:my',
        ]];
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
    private function comments(Staff $staff,int $id,string $cb,int $chat,int $mid): void {$this->requirePermission($staff, Permission::TaskView);$task=$this->service->taskForViewer($id,$staff);if(!$task)throw new DomainException('Vazifa topilmadi.');$this->telegram->answerCallbackQuery($cb);$this->clean($chat,$mid);$comments=$task->comments()->with('staff')->latest()->limit(10)->get()->reverse();$body=$comments->map(fn($c)=>"👤 <b>".e($c->staff?->full_name?:'—')."</b> · ".$c->created_at->format('d.m H:i')."\n".e($c->body))->implode("\n\n");$kb=[]; if($this->has($staff,Permission::TaskCommentCreate))$kb[]=[['text'=>'➕ Izoh yozish','callback_data'=>"tm:comment:add:$id"]]; $kb[]=[['text'=>'⬅️ Vazifaga qaytish','callback_data'=>"tm:view:$id"]]; $this->telegram->sendMessage($chat,"💬 <b>{$task->task_number} izohlari</b>\n\n".($body?:'Hali izoh yo‘q.'),['inline_keyboard'=>$kb],'HTML');}
    private function waitInput(Staff $staff,int $chat,string $cb,int $mid,TelegramConversationState $state,array $context):void{ if($state===TelegramConversationState::WAITING_TASK_MANAGEMENT_COMMENT)$this->requirePermission($staff,Permission::TaskCommentCreate); else $this->requirePermission($staff,Permission::TaskUpdate);$this->telegram->answerCallbackQuery($cb);$this->clean($chat,$mid);$conv=$this->conversations->getOrCreate($staff,$chat);$conv->update(['state'=>$state->value,'context'=>$context,'last_activity_at'=>now()]);$text=$state===TelegramConversationState::WAITING_TASK_MANAGEMENT_COMMENT?'💬 Izohingizni yuboring. Matn, voice, video yoki boshqa media yuborishingiz mumkin.':'✏️ Yangi qiymatni yuboring. Matn, voice yoki video yuborishingiz mumkin.';$this->telegram->sendMessage($chat,$text);}
    private function deadlineMenu(Staff $staff,int $id,string $cb,int $chat,int $mid):void{$this->requirePermission($staff,Permission::TaskUpdate);$task=$this->service->taskForViewer($id,$staff);if(!$task||$task->assignor_id!==$staff->id)throw new DomainException('Ruxsat yo‘q.');$this->telegram->answerCallbackQuery($cb);$this->clean($chat,$mid);$rows=[[60,120],[240,480],[1440,2880],[4320,10080]];$labels=[60=>'⏱ +1 soat',120=>'⏱ +2 soat',240=>'⏱ +4 soat',480=>'⏱ +8 soat',1440=>'📅 +1 kun',2880=>'📅 +2 kun',4320=>'📅 +3 kun',10080=>'📅 +7 kun'];$kb=[];foreach($rows as $row){$kb[]=array_map(fn($m)=>['text'=>$labels[$m],'callback_data'=>"tm:deadline:set:$id:$m"],$row);}$kb[]=[['text'=>'⬅️ Vazifaga qaytish','callback_data'=>"tm:view:$id"]];$this->telegram->sendMessage($chat,'📅 <b>Muddatni uzaytirish</b>'."\n".'<i>Hozirgi muddat: '.TashkentDateTime::format($task->deadline).'. Unga qancha vaqt qo‘shamiz?</i>',['inline_keyboard'=>$kb],'HTML');}
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
    private function reassignList(Staff $staff,int $id,string|int $page,string $cb,int $chat,int $mid):void{$this->requirePermission($staff,Permission::TaskReassign);$task=$this->service->taskForViewer($id,$staff);if(!$task||$task->assignor_id!==$staff->id)throw new DomainException('Ruxsat yo‘q.');$this->telegram->answerCallbackQuery($cb);$this->clean($chat,$mid);$peopleQuery=Staff::query()->where('status','active')->whereNotNull('telegram_chat_id')->where('id','!=',$task->assignee_id)->where('id','!=',$staff->id);
        $perPage=8;
        $lastPage=max(1,(int)ceil((clone $peopleQuery)->count()/$perPage));
        $resolvedPage=match((string)$page){'first'=>1,'last'=>$lastPage,default=>min(max(1,(int)$page),$lastPage)};
        $people=$peopleQuery->orderBy('full_name')->paginate($perPage,['*'],'page',$resolvedPage);$kb=[];foreach($people as $p)$kb[]=[['text'=>$p->full_name,'callback_data'=>"tm:reassign:set:$id:$p->id"]];$nav=[];
        if($people->currentPage()>1)$nav[]=['text'=>'⏮','callback_data'=>"tm:reassign:$id:first"];
        if($people->currentPage()>1)$nav[]=['text'=>'⬅️','callback_data'=>"tm:reassign:$id:".($people->currentPage()-1)];
        $nav[]=['text'=>$people->currentPage().'/'.$people->lastPage(),'callback_data'=>'tm:noop'];
        if($people->hasMorePages())$nav[]=['text'=>'➡️','callback_data'=>"tm:reassign:$id:".($people->currentPage()+1)];
        if($people->currentPage()<$people->lastPage())$nav[]=['text'=>'⏭','callback_data'=>"tm:reassign:$id:last"];
        if($nav)$kb[]=$nav;$kb[]=[['text'=>'⬅️ Vazifaga qaytish','callback_data'=>"tm:view:$id"]];$this->telegram->sendMessage($chat,'🔄 <b>Boshqa xodimga biriktirish</b>'."\n".'<i>Vazifa tanlangan xodimga o‘tadi, hozirgi bajaruvchi esa xabardor qilinadi.</i>',['inline_keyboard'=>$kb],'HTML');}
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
        $this->telegram->editMessageReplyMarkup($chat,$mid,[ 'inline_keyboard'=>[[['text'=>'❌ Ha, vazifani bekor qilaman','callback_data'=>"tm:cancel:yes:$id"]],[['text'=>'↩️ Yo‘q, vazifa qolsin','callback_data'=>"tm:view:$id"]]]]);
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
