@extends('layouts.admin')

@php
    $page = 'staff-show';
    $title = $title ?? 'IMV IB Support — Xodim ma\'lumotlari';
    $initials = collect(preg_split('/\s+/', trim($staff->full_name ?? '')))
        ->filter()->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
@endphp

@section('content')
<main class="app-content staff-profile-content">
    <section class="page-section people-page">
        <header class="staff-profile-hero card">
            <div class="staff-profile-hero__identity">
                <a class="staff-profile-back" href="{{ route('staff.index') }}">← Xodimlar ro‘yxatiga qaytish</a>
                <div class="staff-profile-hero__person">
                    <span class="person-avatar staff-profile-avatar">{{ $initials ?: '?' }}</span>
                    <div>
                        <div class="staff-profile-kicker">Xodim profili</div>
                        <h2>{{ $staff->full_name }}</h2>
                        <p>{{ $staff->lavozim ?: 'Lavozim belgilanmagan' }}</p>
                    </div>
                </div>
            </div>
            <div class="staff-profile-hero__meta">
                <div><span>Jami topshiriqlar</span><strong>{{ $tasks->count() }}</strong></div>
                <div><span>Holat</span><strong class="staff-profile-status">{{ ($staff->status ?? '') === 'active' ? 'Faol' : 'Faol emas' }}</strong></div>
            </div>
        </header>

        <section class="staff-profile-info-grid" aria-label="Xodim ma’lumotlari">
            <article class="staff-info-card card"><span class="staff-info-card__label">Telegram</span><strong>{{ $staff->username ? '@' . ltrim($staff->username, '@') : 'Username mavjud emas' }}</strong><small>ID: {{ $staff->telegram_chat_id ?: '—' }}</small></article>
            <article class="staff-info-card card"><span class="staff-info-card__label">Guruh</span><strong>{{ $staff->group_name ?: 'Biriktirilmagan' }}</strong><small>{{ $staff->group_chat_id ?: 'Guruh ID mavjud emas' }}</small></article>
            <article class="staff-info-card card"><span class="staff-info-card__label">Rollar</span><strong>{{ $staff->roles->pluck('name')->implode(', ') ?: 'Rol biriktirilmagan' }}</strong><small>Tizimdagi ruxsatlar</small></article>
        </section>
    </section>

    <section class="tasks-page tasks-page--workspace staff-tasks-workspace" aria-labelledby="staff-tasks-heading">
        <header class="staff-tasks-heading-card card">
            <div class="staff-tasks-heading-card__icon" aria-hidden="true">✓</div>
            <div class="staff-tasks-heading-card__content">
                <span class="staff-tasks-heading-card__eyebrow">TOPSHIRIQLAR MARKAZI</span>
                <h2 id="staff-tasks-heading">Topshiriqlar</h2>
                <p class="muted">Ushbu xodimga biriktirilgan barcha topshiriqlarni ko‘ring va boshqaring.</p>
            </div>
            <div class="staff-tasks-heading-card__count"><strong>{{ $tasks->count() }}</strong><span>ta topshiriq</span></div>
            <button class="tasks-filter-trigger" data-action="toggle-filters" type="button">Filtrlash</button>
        </header>

        <section aria-label="Topshiriqlar filtrlari" class="tasks-filters card" hidden id="tasks-filters">
            <div class="tasks-filters__header">
                <div><h2>Topshiriqlarni filtrlash</h2><p class="muted">Kerakli topshiriqlarni tez toping.</p></div>
                <div class="tasks-filters__actions"><span class="filter-count" id="active-filter-count">Faol filtr yo‘q</span><button class="btn btn--ghost" data-action="clear-filters" type="button">Tozalash</button></div>
            </div>
            <div class="tasks-filters__grid">
                <label class="field field--search"><span>Qidirish</span><input data-filter="search" id="task-search" placeholder="Sarlavha yoki tavsif" type="search"></label>
                <label class="field field--select"><span>Holat</span><select data-filter="status" data-placeholder="Barcha holatlar" id="task-status-filter"></select></label>
                <label class="field field--select"><span>Ustuvorlik</span><select data-filter="priority" data-placeholder="Barcha ustuvorliklar" id="task-priority-filter"></select></label>
                <label class="field field--select"><span>Muallif</span><select data-filter="creator" data-placeholder="Barcha mualliflar" id="task-creator-filter"></select></label>
                <label class="field field--select"><span>Muddat</span><select data-filter="dueDate" id="task-due-filter"><option value="">Istalgan muddat</option><option value="overdue">Muddati o‘tgan</option><option value="today">Bugun</option><option value="tomorrow">Ertaga</option><option value="this-week">Bu hafta</option><option value="next-week">Keyingi hafta</option><option value="none">Muddat belgilanmagan</option></select></label>
                <div class="field field--date-range"><span>Sana oralig‘i</span><div class="date-range-control"><input data-filter="dueDateFrom" id="task-date-from" type="date"><span>—</span><input data-filter="dueDateTo" id="task-date-to" type="date"></div></div>
            </div>
        </section>

        <section class="tasks-board-shell">
            <section class="tasks-view tasks-view--table" id="table-view">
                <div class="tasks-table-wrap card">
                    <table class="tasks-table">
                        <thead><tr><th>Topshiriq</th><th>Holat</th><th>Ustuvorlik</th><th>Ijrochi</th><th>Muallif</th><th>Muddat</th><th>Yaratilgan</th></tr></thead>
                        <tbody id="tasks-table-body">
                            @forelse($tasks as $task)
                                @php
                                    $statusValue = $task->status instanceof \BackedEnum ? $task->status->value : $task->status;
                                    $priorityValue = $task->priority instanceof \BackedEnum ? $task->priority->value : ($task->priority ?: 'NORMAL');
                                    $statusLabel = match(strtoupper((string) $statusValue)) {
                                        'CREATED', 'NEW' => 'Yangi',
                                        'ASSIGNED' => 'Biriktirildi',
                                        'ACCEPTED' => 'Qabul qilindi',
                                        'IN_PROGRESS' => 'Jarayonda',
                                        'PAUSED' => 'To‘xtatilgan',
                                        'SUBMITTED', 'AWAITING_ACCEPTANCE' => 'Qabul kutmoqda',
                                        'REVIEW' => 'Ko‘rib chiqilmoqda',
                                        'COMPLETION_APPROVED', 'APPROVED' => 'Tasdiqlandi',
                                        'RETURNED' => 'Qaytarildi',
                                        'CANCELLED' => 'Bekor qilindi',
                                        'REJECTED' => 'Rad etildi',
                                        'CLOSED' => 'Yopildi',
                                        default => 'Boshqa'
                                    };
                                    $priorityLabel = match(strtoupper((string) $priorityValue)) {
                                        'CRITICAL' => 'Juda yuqori',
                                        'HIGH' => 'Yuqori',
                                        'MEDIUM' => 'O‘rtacha',
                                        'LOW' => 'Past',
                                        default => 'Oddiy'
                                    };
                                    $assigneeName = $task->assignee?->full_name ?: 'Biriktirilmagan';
                                    $assigneeInitials = collect(preg_split('/\s+/', trim($assigneeName)))->filter()->take(2)->map(fn($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
                                @endphp
                                <tr class="task-row" data-task-id="{{ $task->id }}" tabindex="0">
                                    <td><div class="table-task"><strong>{{ $task->title ?: 'Nomsiz topshiriq' }}</strong><span>{{ $task->task_number ?: 'TASK-' . $task->id }}</span></div></td>
                                    <td><span class="status-badge" data-status="{{ strtoupper((string) $statusValue) }}">{{ $statusLabel }}</span></td>
                                    <td><span class="priority-badge" data-priority="{{ strtoupper((string) $priorityValue) }}">{{ $priorityLabel }}</span></td>
                                    <td><span class="table-person"><span class="avatar avatar--sm">{{ $assigneeInitials ?: '—' }}</span><span>{{ $assigneeName }}</span></span></td>
                                    <td>{{ $task->author?->full_name ?: '—' }}</td>
                                    <td>{{ $task->deadline?->format('d.m.Y') ?: '—' }}</td>
                                    <td>{{ $task->created_at?->format('d.m.Y') ?: '—' }}</td>
                                </tr>
                            @empty
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <footer class="tasks-table-footer"><p class="muted" data-table-summary></p><div class="pagination"><button class="pagination-button" data-action="table-prev" type="button">‹</button><div data-table-pages></div><button class="pagination-button" data-action="table-next" type="button">›</button></div></footer>
            </section>
            <p class="empty-state" hidden id="tasks-empty-state">Topshiriqlar topilmadi.</p>
        </section>
    </section>
</main>
@endsection

@section('overlays')
@include('components.task-detail-drawer')
<template id="task-row-template"><tr class="task-row" data-task-id="" tabindex="0"><td><div class="table-task"><strong data-task-field="title"></strong><span data-task-field="number"></span></div></td><td><span class="status-badge" data-task-field="status"></span></td><td><span class="priority-badge" data-task-field="priority"></span></td><td><span class="table-person"><span class="avatar avatar--sm" data-task-field="assignee-initials"></span><span data-task-field="assignee"></span></span></td><td data-task-field="creator"></td><td><time data-task-field="due-date"></time></td><td><time data-task-field="created-at"></time></td></tr></template>
<template id="filter-option-template"><option value=""></option></template>
<template id="task-label-template"><span class="task-label" data-task-label=""></span></template>
<template id="checklist-item-template"><label class="checklist-item"><input data-checklist-complete type="checkbox"><span data-checklist-title></span></label></template>
<template id="history-item-template"><li class="history-item"><span class="history-item__dot"></span><div><strong data-history-title></strong><p data-history-description></p><time data-history-time></time></div></li></template>
<template id="pagination-page-template"><button class="pagination-page" data-action="table-page" type="button"></button></template>
<div aria-live="polite" class="toast-region" id="toast-region"></div>
@endsection

@push('scripts')
@php
$tasksData = $tasks->map(fn ($task) => [
    'id' => $task->id,
    'number' => $task->task_number ?? ('TASK-' . $task->id),
    'title' => $task->title,
    'description' => $task->description,
    'status' => $task->status instanceof \BackedEnum ? $task->status->value : $task->status,
    'priority' => $task->priority instanceof \BackedEnum ? $task->priority->value : $task->priority,
    'assignee' => ['id' => $task->assignee?->id, 'name' => $task->assignee?->full_name],
    'creator' => ['id' => $task->author?->id, 'name' => $task->author?->full_name],
    'assignor' => ['id' => $task->assignor?->id, 'name' => $task->assignor?->full_name],
    'dueAt' => $task->deadline?->toISOString(), 'deadline' => $task->deadline?->toISOString(),
    'createdAt' => $task->created_at?->toISOString(), 'assignedAt' => $task->started_at?->toISOString(),
    'startedAt' => $task->started_at?->toISOString(), 'completedAt' => $task->completed_at?->toISOString(), 'closedAt' => $task->closed_at?->toISOString(),
    'statusDates' => ['created' => $task->created_at?->toISOString(), 'assigned' => $task->created_at?->toISOString(), 'in_progress' => $task->started_at?->toISOString(), 'awaiting_acceptance' => $task->completed_at?->toISOString(), 'accepted' => $task->completed_at?->toISOString(), 'completion_approved' => $task->completed_at?->toISOString(), 'closed' => $task->closed_at?->toISOString()],
    'sourceType' => $task->source_type, 'sourceMessageId' => $task->source_message_id, 'sourceUrl' => $task->source_url, 'sourceText' => $task->description ?? '', 'confidence' => $task->ajralish_aniqligi,
    'comments' => $task->comments->map(fn ($comment) => ['id'=>$comment->id, 'body'=>$comment->body ?? $comment->comment ?? '', 'createdAt'=>$comment->created_at?->toISOString(), 'staff'=>['id'=>$comment->staff?->id, 'name'=>$comment->staff?->full_name]])->values(),
    'logs' => $task->logs->map(fn ($log) => ['id'=>$log->id, 'createdAt'=>$log->created_at?->toISOString(), 'actor'=>$log->actor?->full_name, 'fromAssignee'=>$log->fromAssignee?->full_name, 'toAssignee'=>$log->toAssignee?->full_name, 'action'=>$log->action ?? '', 'description'=>$log->description ?? ''])->values(),
    'sprints' => $task->sprints->map(fn ($sprint) => ['id'=>$sprint->id, 'name'=>$sprint->name])->values(),
])->values();
@endphp
<script>window.tasksData = @json($tasksData);</script>
@endpush


@push('scripts-after-app')
<script>
(function () {
    const tasks = Array.isArray(window.tasksData) ? window.tasksData : [];

    const statusText = {
        CREATED: 'Yangi', NEW: 'Yangi', ASSIGNED: 'Biriktirildi',
        ACCEPTED: 'Qabul qilindi', IN_PROGRESS: 'Jarayonda', PAUSED: 'To‘xtatilgan',
        SUBMITTED: 'Qabul kutmoqda', AWAITING_ACCEPTANCE: 'Qabul kutmoqda',
        REVIEW: 'Ko‘rib chiqilmoqda', COMPLETION_APPROVED: 'Tasdiqlandi', APPROVED: 'Tasdiqlandi',
        RETURNED: 'Qaytarildi', CANCELLED: 'Bekor qilindi', REJECTED: 'Rad etildi', CLOSED: 'Yopildi'
    };

    const priorityText = {
        CRITICAL: 'Juda yuqori', HIGH: 'Yuqori', MEDIUM: 'O‘rtacha', NORMAL: 'Oddiy', LOW: 'Past'
    };

    const normalize = (value) => String(value || '').trim().toUpperCase();
    const initials = (name) => String(name || '—').split(/\s+/).filter(Boolean).slice(0, 2).map(x => x[0]).join('').toUpperCase() || '—';
    const formatDate = (value) => {
        if (!value) return '—';
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('uz-UZ');
    };
    const formatDateTime = (value) => {
        if (!value) return '—';
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return '—';
        const pad = (n) => String(n).padStart(2, '0');
        return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}`;
    };

    function filteredTasks() {
        const search = document.getElementById('task-search')?.value.trim().toLowerCase() || '';
        const status = document.getElementById('task-status-filter')?.value || '';
        const priority = document.getElementById('task-priority-filter')?.value || '';
        const creator = document.getElementById('task-creator-filter')?.value || '';
        const due = document.getElementById('task-due-filter')?.value || '';

        return tasks.filter(task => {
            const taskStatus = normalize(task.status);
            const taskPriority = normalize(task.priority) || 'NORMAL';
            const creatorId = String(task.creator?.id ?? '');
            const haystack = [task.title, task.description, task.number, task.creator?.name, task.assignee?.name].join(' ').toLowerCase();

            if (search && !haystack.includes(search)) return false;
            if (status && taskStatus !== normalize(status)) return false;
            if (priority && taskPriority !== normalize(priority)) return false;
            if (creator && creatorId !== String(creator)) return false;

            if (due) {
                if (!task.dueAt && due !== 'none') return false;
                if (due === 'none' && task.dueAt) return false;
            }
            return true;
        });
    }

    function render() {
        const body = document.getElementById('tasks-table-body');
        if (!body) return;

        const rows = filteredTasks();
        body.innerHTML = '';

        rows.forEach(task => {
            const tr = document.createElement('tr');
            tr.className = 'task-row';
            tr.tabIndex = 0;
            tr.dataset.taskId = task.id;
            const status = normalize(task.status);
            const priority = normalize(task.priority) || 'NORMAL';
            const assigneeName = task.assignee?.name || 'Biriktirilmagan';
            const creatorName = task.creator?.name || '—';

            tr.innerHTML = `
                <td><div class="table-task"><strong></strong><span></span></div></td>
                <td><span class="status-badge" data-status="${status}"></span></td>
                <td><span class="priority-badge" data-priority="${priority}"></span></td>
                <td><span class="table-person"><span class="avatar avatar--sm"></span><span class="assignee-name"></span></span></td>
                <td class="creator-name"></td>
                <td class="due-date"></td>
                <td class="created-date"></td>`;

            tr.querySelector('.table-task strong').textContent = task.title || 'Nomsiz topshiriq';
            tr.querySelector('.table-task span').textContent = task.number || ('TASK-' + task.id);
            tr.querySelector('.status-badge').textContent = statusText[status] || task.status || '—';
            tr.querySelector('.priority-badge').textContent = priorityText[priority] || task.priority || 'Oddiy';
            tr.querySelector('.avatar').textContent = initials(assigneeName);
            tr.querySelector('.assignee-name').textContent = assigneeName;
            tr.querySelector('.creator-name').textContent = creatorName;
            tr.querySelector('.due-date').textContent = formatDate(task.dueAt || task.deadline);
            tr.querySelector('.created-date').textContent = formatDate(task.createdAt);

            body.appendChild(tr);
        });

        const empty = document.getElementById('tasks-empty-state');
        if (empty) empty.hidden = rows.length !== 0;
        const summary = document.querySelector('[data-table-summary]');
        if (summary) summary.textContent = rows.length ? `1–${rows.length} / ${rows.length}` : '0 / 0';
    }

    function fillSelect(select, values, placeholder) {
        if (!select) return;
        const current = select.value;
        select.innerHTML = `<option value="">${placeholder}</option>`;
        values.forEach(([value, label]) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            select.appendChild(option);
        });
        select.value = current;
    }

    function initialize() {
        // This renderer intentionally runs after app.js. app.js has generic task-page
        // lifecycle logic that can clear this staff-only table during refresh.
        fillSelect(document.getElementById('task-status-filter'),
            [...new Set(tasks.map(t => normalize(t.status)).filter(Boolean))].map(v => [v, statusText[v] || v]),
            'Barcha holatlar');
        fillSelect(document.getElementById('task-priority-filter'),
            [...new Set(tasks.map(t => normalize(t.priority) || 'NORMAL'))].map(v => [v, priorityText[v] || v]),
            'Barcha ustuvorliklar');
        fillSelect(document.getElementById('task-creator-filter'),
            [...new Map(tasks.filter(t => t.creator?.id).map(t => [String(t.creator.id), t.creator.name || 'Noma’lum'])).entries()],
            'Barcha mualliflar');

        ['task-search', 'task-status-filter', 'task-priority-filter', 'task-creator-filter', 'task-due-filter', 'task-date-from', 'task-date-to']
            .forEach(id => document.getElementById(id)?.addEventListener('input', render));
        ['task-status-filter', 'task-priority-filter', 'task-creator-filter', 'task-due-filter']
            .forEach(id => document.getElementById(id)?.addEventListener('change', render));

        // Staff page owns its filter panel because the global task-page binder is not used here.
        document.querySelector('[data-action="toggle-filters"]')?.addEventListener('click', () => {
            const filters = document.getElementById('tasks-filters');
            if (filters) filters.hidden = !filters.hidden;
        });

        document.querySelector('[data-action="clear-filters"]')?.addEventListener('click', () => {
            ['task-search', 'task-status-filter', 'task-priority-filter', 'task-creator-filter', 'task-due-filter', 'task-date-from', 'task-date-to']
                .forEach(id => {
                    const element = document.getElementById(id);
                    if (!element) return;
                    element.value = '';
                });
            render();
        });

        render();
    }

    /*
     * Staff show page uses its own task drawer opener. The generic app.js task
     * renderer expects the full Tasks-page dataset and can fail on staff-only data.
     * Capture row clicks before the global document handler and populate the shared
     * drawer directly.
     */
    function setDetail(selector, value) {
        document.querySelectorAll(selector).forEach(el => { el.textContent = value ?? '—'; });
    }

    function setDetailAttr(selector, attr, value) {
        document.querySelectorAll(selector).forEach(el => {
            if (value) el.setAttribute(attr, value);
            else el.removeAttribute(attr);
        });
    }

    function updateStaffStatusLine(task) {
        const panel = document.getElementById('task-detail-panel');
        if (!panel) return;

        const statusOrder = [
            'created',
            'assigned',
            'accepted',
            'in_progress',
            'awaiting_acceptance',
            'completion_approved',
            'closed'
        ];

        const aliases = {
            NEW: 'created',
            CREATED: 'created',
            ASSIGNED: 'assigned',
            ACCEPTED: 'accepted',
            IN_PROGRESS: 'in_progress',
            SUBMITTED: 'awaiting_acceptance',
            AWAITING_ACCEPTANCE: 'awaiting_acceptance',
            COMPLETION_APPROVED: 'completion_approved',
            APPROVED: 'completion_approved',
            CLOSED: 'closed'
        };

        const rawStatus = normalize(task.status);
        const currentStatus = aliases[rawStatus] || rawStatus.toLowerCase();
        const currentIndex = statusOrder.indexOf(currentStatus);

        panel.querySelectorAll('[data-status-step]').forEach(step => {
            const stepStatus = String(step.dataset.statusStep || '').toLowerCase();
            const stepIndex = statusOrder.indexOf(stepStatus);
            const dot = step.querySelector('.task-status-line__dot');
            const time = step.querySelector('[data-status-time]');

            step.classList.remove('is-active', 'is-current', 'is-completed', 'is-complete');

            if (currentIndex !== -1 && stepIndex < currentIndex) {
                step.classList.add('is-completed', 'is-complete');
                if (dot) dot.textContent = '✓';
            } else if (currentIndex !== -1 && stepIndex === currentIndex) {
                step.classList.add('is-active', 'is-current');
                if (dot) dot.textContent = '✓';
            } else if (dot) {
                dot.textContent = '•';
            }

            const dateValue = task.statusDates?.[stepStatus] || null;
            if (time) {
                time.textContent = formatDateTime(dateValue);
                if (dateValue) time.dateTime = dateValue;
                else time.removeAttribute('datetime');
            }
        });
    }

    const sourceTypeText = {
        text: 'Matn',
        voice: 'Ovozli xabar',
        audio: 'Ovozli xabar',
        telegram: 'Telegram xabari',
        file: 'Fayl',
        document: 'Hujjat',
        image: 'Rasm',
        photo: 'Rasm',
        video: 'Video',
        message: 'Xabar'
    };

    function formatConfidence(value) {
        if (value === null || value === undefined || value === '') return '—';
        const number = Number(value);
        if (!Number.isFinite(number)) return '—';
        const percent = number >= 0 && number <= 1 ? number * 100 : number;
        return `${Math.round(percent)}%`;
    }

    function renderStaffComments(task) {
        const panel = document.getElementById('task-detail-panel');
        if (!panel) return;
        const comments = Array.isArray(task.comments) ? task.comments : [];
        const list = panel.querySelector('[data-task-comments]');
        const empty = panel.querySelector('[data-task-comments-empty]');
        if (list) {
            list.innerHTML = '';
            comments.forEach(comment => {
                const item = document.createElement('article');
                item.className = 'comment-item';
                const header = document.createElement('header');
                const author = document.createElement('strong');
                const time = document.createElement('time');
                const body = document.createElement('p');
                author.textContent = comment.staff?.name || 'Noma’lum';
                time.textContent = formatDate(comment.createdAt);
                body.className = 'comment-item__body';
                body.textContent = comment.body || '';
                header.append(author, time);
                item.append(header, body);
                list.appendChild(item);
            });
        }
        if (empty) empty.hidden = comments.length > 0;
        setDetail('[data-task-detail="comment-count"]', String(comments.length));
        setDetail('[data-task-detail="comment-count-side"]', String(comments.length));
    }

    function renderStaffHistory(task) {
        const panel = document.getElementById('task-detail-panel');
        if (!panel) return;
        const logs = Array.isArray(task.logs) ? task.logs : [];
        const list = panel.querySelector('[data-task-history]');
        if (list) {
            list.innerHTML = '';
            logs.forEach(log => {
                const item = document.createElement('li');
                item.className = 'history-item';
                const dot = document.createElement('span');
                dot.className = 'history-item__dot';
                const content = document.createElement('div');
                const title = document.createElement('strong');
                const description = document.createElement('p');
                const time = document.createElement('time');
                title.textContent = log.action || 'O‘zgarish';
                description.textContent = log.description || [log.fromAssignee, log.toAssignee].filter(Boolean).join(' → ') || 'Topshiriq tarixi yangilandi.';
                time.textContent = [log.actor, formatDate(log.createdAt)].filter(Boolean).join(' · ');
                content.append(title, description, time);
                item.append(dot, content);
                list.appendChild(item);
            });
        }
        setDetail('[data-task-detail="history-count"]', String(logs.length));
        setDetail('[data-task-detail="history-count-side"]', String(logs.length));
    }

    function updateStaffChain(task) {
        const panel = document.getElementById('task-detail-panel');
        if (!panel) return;
        const summary = panel.querySelector('[data-task-chain-summary]');
        if (summary) {
            const sprintCount = Array.isArray(task.sprints) ? task.sprints.length : 0;
            summary.textContent = sprintCount
                ? `Ushbu topshiriq ${sprintCount} ta sprint bilan bog‘langan.`
                : 'Ushbu topshiriq mustaqil topshiriq.';
        }
    }

    function bindStaffDetailTabs() {
        const panel = document.getElementById('task-detail-panel');
        if (!panel) return;
        panel.querySelectorAll('[data-action="detail-tab"]').forEach(button => {
            button.addEventListener('click', event => {
                event.preventDefault();
                event.stopPropagation();
                const tabName = button.dataset.detailTab;
                panel.querySelectorAll('[data-detail-panel]').forEach(tabPanel => {
                    const active = tabPanel.dataset.detailPanel === tabName;
                    tabPanel.hidden = !active;
                    tabPanel.classList.toggle('is-active', active);
                });
                panel.querySelectorAll('[data-action="detail-tab"]').forEach(tabButton => {
                    const active = tabButton.dataset.detailTab === tabName;
                    tabButton.classList.toggle('is-active', active);
                    tabButton.setAttribute('aria-selected', String(active));
                });
            });
        });
    }

    function openStaffTaskDrawer(taskId) {
        const task = tasks.find(t => String(t.id) === String(taskId));
        if (!task) return;

        const panel = document.getElementById('task-detail-panel');
        const backdrop = document.getElementById('task-detail-backdrop');
        if (!panel) return;

        const status = normalize(task.status);
        const priority = normalize(task.priority) || 'NORMAL';
        const creator = task.creator?.name || 'Noma’lum';
        const assignee = task.assignee?.name || 'Biriktirilmagan';
        const due = formatDate(task.dueAt || task.deadline);
        const created = formatDate(task.createdAt);
        const sourceText = task.sourceText || task.description || 'Manba xabari mavjud emas.';

        panel.dataset.taskId = String(task.id);
        setDetail('[data-task-detail="number"]', task.number || ('TASK-' + task.id));
        setDetail('[data-task-detail="title"]', task.title || 'Nomsiz topshiriq');
        setDetail('[data-task-detail="title-chain"]', task.title || 'Nomsiz topshiriq');
        setDetail('[data-task-detail="number-chain"]', task.number || ('TASK-' + task.id));
        setDetail('[data-task-detail="group"]', 'Topshiriqlar');
        setDetail('[data-task-detail="description"]', task.description || 'Tavsif kiritilmagan.');
        setDetail('[data-task-detail="source-text"]', sourceText);
        const sourceType = String(task.sourceType || 'message').toLowerCase();
        setDetail('[data-task-detail="source-kind"]', sourceTypeText[sourceType] || 'Xabar');
        setDetail('[data-task-detail="status"]', statusText[status] || task.status || '—');
        setDetail('[data-task-detail="status-side"]', statusText[status] || task.status || '—');
        setDetail('[data-task-detail="priority"]', priorityText[priority] || 'Oddiy');
        setDetail('[data-task-detail="priority-side"]', priorityText[priority] || 'Oddiy');
        setDetail('[data-task-detail="assignee"]', assignee);
        setDetail('[data-task-detail="creator"]', creator);
        setDetail('[data-task-detail="creator-side"]', creator);
        setDetail('[data-task-detail="created-at"]', created);
        setDetail('[data-task-detail="created-at-side"]', created);
        setDetail('[data-task-detail="due-date"]', due);
        setDetail('[data-task-detail="deadline-label"]', task.dueAt || task.deadline ? 'Belgilangan muddat' : 'Muddat belgilanmagan');
        setDetail('[data-task-detail="deadline-value"]', due);
        setDetail('[data-task-detail="creator-initials"]', initials(creator));
        setDetail('[data-task-detail="creator-side-initials"]', initials(creator));
        const confidenceText = formatConfidence(task.confidence);
        setDetail('[data-task-detail="confidence-value"]', confidenceText);
        setDetail('[data-task-detail="confidence"]', confidenceText === '—' ? 'Aniqlik mavjud emas' : `Ajratish aniqligi ${confidenceText}`);

        document.querySelectorAll('[data-task-detail="status"], [data-task-detail="status-side"]').forEach(el => el.dataset.status = status);
        document.querySelectorAll('[data-task-detail="priority"], [data-task-detail="priority-side"]').forEach(el => el.dataset.priority = priority);

        // The staff page owns its drawer opener, so it must also update the shared
        // status timeline explicitly (the global Tasks-page function is not called here).
        updateStaffStatusLine(task);
        updateStaffChain(task);
        renderStaffComments(task);
        renderStaffHistory(task);

        // Always open the first tab when a new task is selected.
        document.querySelectorAll('[data-detail-panel]').forEach(el => {
            const active = el.dataset.detailPanel === 'details';
            el.hidden = !active;
            el.classList.toggle('is-active', active);
        });
        document.querySelectorAll('[data-action="detail-tab"]').forEach(el => {
            const active = el.dataset.detailTab === 'details';
            el.classList.toggle('is-active', active);
            el.setAttribute('aria-selected', String(active));
        });

        panel.hidden = false;
        if (backdrop) backdrop.hidden = false;
        document.body.classList.add('has-task-detail');
    }

    // Capture phase prevents the generic app.js task-row handler from taking over.
    document.addEventListener('click', function(event) {
        const row = event.target.closest('.staff-tasks-workspace .task-row[data-task-id]');
        if (!row || event.target.closest('button, a, input, select, textarea')) return;
        event.preventDefault();
        event.stopPropagation();
        openStaffTaskDrawer(row.dataset.taskId);
    }, true);

    document.addEventListener('keydown', function(event) {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        const row = event.target.closest('.staff-tasks-workspace .task-row[data-task-id]');
        if (!row) return;
        event.preventDefault();
        event.stopPropagation();
        openStaffTaskDrawer(row.dataset.taskId);
    }, true);

    // Staff page-owned close handler.
    document.querySelectorAll('#task-detail-backdrop, .task-detail-close').forEach(el => {
        el.addEventListener('click', () => {
            const panel = document.getElementById('task-detail-panel');
            const backdrop = document.getElementById('task-detail-backdrop');
            if (panel) panel.hidden = true;
            if (backdrop) backdrop.hidden = true;
            document.body.classList.remove('has-task-detail');
        });
    });

    // Staff page owns tab switching because the global task-page binder is disabled here.
    bindStaffDetailTabs();

    // app.js is loaded immediately before this stack, so this runs after its initialization.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initialize);
    } else {
        initialize();
    }
})();
</script>
@endpush
