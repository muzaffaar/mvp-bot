@extends('layouts.admin')

@php
$page = $page ?? 'tasks';
$title = $title ?? 'IMV IB Support — Topshiriqlar';
@endphp

@section('content')
<main class="app-content tasks-content">
    <section aria-labelledby="tasks-heading" class="tasks-page tasks-page--workspace">
        <header class="tasks-workspace-header">
            <div aria-label="Tezkor topshiriq filtrlari" class="tasks-quick-tabs" role="tablist">
                <button class="tasks-quick-tab is-active" data-quick-filter="all" type="button">Barchasi</button>
                <button class="tasks-quick-tab" data-quick-filter="mine" type="button">Mening
                    topshiriqlarim</button>
                <button class="tasks-quick-tab" data-quick-filter="pending" type="button">Qabulni
                    kutmoqda</button>
                <button class="tasks-quick-tab" data-quick-filter="risk" type="button">Muddat xavfi</button>
                <button class="tasks-quick-tab" data-quick-filter="unassigned" type="button">Biriktirilmagan</button>
                <button class="tasks-filter-trigger" data-action="toggle-filters" type="button">＋
                    Filtr</button>
            </div>
            <div class="page-toolbar__actions">
                <div aria-label="Ko‘rinish turi" class="tasks-view-toggle" role="group">
                    <button aria-pressed="true" class="segmented-button is-active" data-action="change-view"
                        data-view="kanban" type="button">▥ Kanban</button>
                    <button aria-pressed="false" class="segmented-button" data-action="change-view" data-view="table"
                        type="button">▦ Jadval</button>
                </div>
            </div>
        </header>
        <section aria-label="Kengaytirilgan filtrlar" class="tasks-filters card" hidden="" id="tasks-filters">
            <div class="tasks-filters__header">
                <div>
                    <h2 id="tasks-heading">Topshiriqlarni filtrlash</h2>
                    <p class="muted">Bir nechta filtr birgalikda ishlaydi.</p>
                </div>
                <div class="tasks-filters__actions">
                    <span class="filter-count" id="active-filter-count">Faol filtr yo‘q</span>
                    <button class="btn btn--ghost" data-action="clear-filters" type="button">Barchasini
                        tozalash</button>
                </div>
            </div>
            <div class="tasks-filters__grid">
                <label class="field field--search">
                    <span>Qidirish</span>
                    <input data-filter="search" id="task-search" placeholder="Sarlavha, tavsif yoki xodim"
                        type="search" />
                </label>
                <label class="field field--select">
                    <span>Holat</span>
                    <select aria-label="Holat bo‘yicha filtrlash" data-filter="status"
                        data-placeholder="Barcha holatlar" id="task-status-filter"></select>
                </label>
                <label class="field field--select">
                    <span>Ustuvorlik</span>
                    <select aria-label="Ustuvorlik bo‘yicha filtrlash" data-filter="priority"
                        data-placeholder="Barcha ustuvorliklar" id="task-priority-filter"></select>
                </label>
                <label class="field field--select">
                    <span>Ijrochi</span>
                    <select aria-label="Ijrochi bo‘yicha filtrlash" data-filter="assignee"
                        data-placeholder="Barcha ijrochilar" id="task-assignee-filter"></select>
                </label>
                <label class="field field--select">
                    <span>Muallif</span>
                    <select aria-label="Muallif bo‘yicha filtrlash" data-filter="creator"
                        data-placeholder="Barcha mualliflar" id="task-creator-filter"></select>
                </label>
                <label class="field field--select">
                    <span>Muddat</span>
                    <select aria-label="Muddat bo‘yicha filtrlash" data-filter="dueDate" id="task-due-filter">
                        <option value="">Istalgan muddat</option>
                        <option value="overdue">Muddati o‘tgan</option>
                        <option value="today">Bugun</option>
                        <option value="tomorrow">Ertaga</option>
                        <option value="this-week">Bu hafta</option>
                        <option value="next-week">Keyingi hafta</option>
                        <option value="none">Muddat belgilanmagan</option>
                    </select>
                </label>
                <div aria-labelledby="task-date-range-label" class="field field--date-range" role="group">
                    <span id="task-date-range-label">Sana oralig‘i</span>
                    <div class="date-range-control">
                        <label>
                            <span class="sr-only">Boshlanish sanasi</span>
                            <input data-filter="dueDateFrom" id="task-date-from" type="date" />
                        </label>
                        <span aria-hidden="true" class="date-range-control__separator">—</span>
                        <label>
                            <span class="sr-only">Tugash sanasi</span>
                            <input data-filter="dueDateTo" id="task-date-to" type="date" />
                        </label>
                    </div>
                </div>
            </div>
        </section>
        <section aria-label="Topshiriqlar ish maydoni" class="tasks-board-shell">
            <section aria-label="Kanban doskasi" class="tasks-view tasks-view--kanban" id="kanban-view">

                {{-- NEW --}}
                <section class="kanban-column" data-status="NEW">

                    <header class="kanban-column__header">
                        <div>
                            <span class="kanban-column__indicator kanban-column__indicator--new"></span>

                            <h2>Yangi</h2>

                            <span class="kanban-column__count" data-column-count="NEW">
                                {{ $tasksByStatus['NEW']->count() }}
                            </span>
                        </div>

                        <button aria-label="Yangi ustun menyusi" class="kanban-column__menu" type="button">
                            ⋯
                        </button>
                    </header>


                    <div class="kanban-column__tasks" data-kanban-tasks="NEW">

                        @forelse ($tasksByStatus['NEW'] as $task)

                        <article class="task-card" data-task-id="{{ $task->id }}" role="button" tabindex="0">

                            <header class="task-card__meta">
                                <span class="task-card__number">
                                    {{ $task->task_number ?? 'TASK-' . $task->id }}
                                </span>

                                <button aria-label="Topshiriq menyusi" class="task-card__menu" type="button">
                                    ⋯
                                </button>
                            </header>


                            <h3 class="task-card__title">
                                {{ $task->title }}
                            </h3>


                            @if ($task->description)
                            <p class="task-card__description">
                                {{ $task->description }}
                            </p>
                            @endif


                            @if ($task->sprints->isNotEmpty())
                            <div class="task-card__labels">
                                @foreach ($task->sprints as $sprint)
                                <span class="task-label">
                                    {{ $sprint->name }}
                                </span>
                                @endforeach
                            </div>
                            @endif


                            <footer class="task-card__footer">

                                <span class="task-card__person">

                                    <span class="avatar avatar--sm">
                                        {{ strtoupper(substr($task->assignee?->full_name ?? '?', 0, 1)) }}
                                    </span>

                                    <span>
                                        {{ $task->assignee?->full_name ?? 'Biriktirilmagan' }}
                                    </span>

                                </span>


                                @if ($task->deadline)
                                    <time datetime="{{ $task->deadline->toISOString() }}">
                                        {{ $task->deadline->format('d.m.Y H:i') }}
                                    </time>
                                @else
                                    <time>—</time>
                                @endif

                            </footer>

                        </article>

                        @empty

                        <p class="kanban-column__empty">
                            Yangi topshiriqlar yo‘q
                        </p>

                        @endforelse

                    </div>


                    {{-- @can('task.create')
                    <button class="kanban-column__add" data-action="open-create-task" type="button">
                        ＋ Qo‘shish
                    </button>
                    @endcan --}}

                </section>



                {{-- ASSIGNED --}}
                <section class="kanban-column" data-status="ASSIGNED">

                    <header class="kanban-column__header">
                        <div>
                            <span class="kanban-column__indicator kanban-column__indicator--assigned"></span>

                            <h2>Biriktirildi</h2>

                            <span class="kanban-column__count" data-column-count="ASSIGNED">
                                {{ $tasksByStatus['ASSIGNED']->count() }}
                            </span>
                        </div>

                        <button aria-label="Biriktirilgan ustun menyusi" class="kanban-column__menu" type="button">
                            ⋯
                        </button>
                    </header>


                    <div class="kanban-column__tasks" data-kanban-tasks="ASSIGNED">

                        @forelse ($tasksByStatus['ASSIGNED'] as $task)

                        <article class="task-card" data-task-id="{{ $task->id }}" role="button" tabindex="0">

                            <header class="task-card__meta">

                                <span class="task-card__number">
                                    {{ $task->task_number ?? 'TASK-' . $task->id }}
                                </span>

                                <button aria-label="Topshiriq menyusi" class="task-card__menu" type="button">
                                    ⋯
                                </button>

                            </header>


                            <h3 class="task-card__title">
                                {{ $task->title }}
                            </h3>


                            @if ($task->description)
                            <p class="task-card__description">
                                {{ $task->description }}
                            </p>
                            @endif


                            @if ($task->sprints->isNotEmpty())
                            <div class="task-card__labels">
                                @foreach ($task->sprints as $sprint)
                                <span class="task-label">
                                    {{ $sprint->name }}
                                </span>
                                @endforeach
                            </div>
                            @endif


                            <footer class="task-card__footer">

                                <span class="task-card__person">

                                    <span class="avatar avatar--sm">
                                        {{ strtoupper(substr($task->assignee?->full_name ?? '?', 0, 1)) }}
                                    </span>

                                    <span>
                                        {{ $task->assignee?->full_name ?? 'Biriktirilmagan' }}
                                    </span>

                                </span>


                                @if ($task->deadline)
                                <time datetime="{{ $task->deadline->toISOString() }}">
                                    {{ $task->deadline->format('d.m.Y H:i') }}
                                </time>
                                @endif

                            </footer>

                        </article>

                        @empty

                        <p class="kanban-column__empty">
                            Biriktirilgan topshiriqlar yo‘q
                        </p>

                        @endforelse

                    </div>

                </section>

                {{-- ACCEPTED --}}
                <section class="kanban-column" data-status="ACCEPTED">

                    <header class="kanban-column__header">

                        <div>
                            <span class="kanban-column__indicator kanban-column__indicator--done"></span>

                            <h2>Qabul qilindi</h2>

                            <span class="kanban-column__count" data-column-count="ACCEPTED">
                                {{ $tasksByStatus['ACCEPTED']->count() }}
                            </span>
                        </div>

                        <button aria-label="Qabul qilingan ustun menyusi" class="kanban-column__menu" type="button">
                            ⋯
                        </button>

                    </header>


                    <div class="kanban-column__tasks" data-kanban-tasks="ACCEPTED">

                        @forelse ($tasksByStatus['ACCEPTED'] as $task)

                        <article class="task-card" data-task-id="{{ $task->id }}" role="button" tabindex="0">

                            <header class="task-card__meta">

                                <span class="task-card__number">
                                    {{ $task->task_number ?? 'TASK-' . $task->id }}
                                </span>

                                <button aria-label="Topshiriq menyusi" class="task-card__menu" type="button">
                                    ⋯
                                </button>

                            </header>


                            <h3 class="task-card__title">
                                {{ $task->title }}
                            </h3>


                            @if ($task->description)
                            <p class="task-card__description">
                                {{ $task->description }}
                            </p>
                            @endif


                            @if ($task->sprints->isNotEmpty())
                            <div class="task-card__labels">

                                @foreach ($task->sprints as $sprint)
                                <span class="task-label">
                                    {{ $sprint->name }}
                                </span>
                                @endforeach

                            </div>
                            @endif


                            <footer class="task-card__footer">

                                <span class="task-card__person">

                                    <span class="avatar avatar--sm">
                                        {{ strtoupper(substr($task->assignee?->full_name ?? '?', 0, 1)) }}
                                    </span>

                                    <span>
                                        {{ $task->assignee?->full_name ?? 'Biriktirilmagan' }}
                                    </span>

                                </span>


                                @if ($task->deadline)
                                    <time datetime="{{ $task->deadline->toISOString() }}">
                                        {{ $task->deadline->format('d.m.Y H:i') }}
                                    </time>
                                @else
                                    <time>—</time>
                                @endif

                            </footer>

                        </article>

                        @empty

                        <p class="kanban-column__empty">
                            Qabul qilingan topshiriqlar yo‘q
                        </p>

                        @endforelse

                    </div>

                </section>

                {{-- IN PROGRESS --}}
                <section class="kanban-column" data-status="IN_PROGRESS">

                    <header class="kanban-column__header">

                        <div>
                            <span class="kanban-column__indicator kanban-column__indicator--progress"></span>

                            <h2>Jarayonda</h2>

                            <span class="kanban-column__count" data-column-count="IN_PROGRESS">
                                {{ $tasksByStatus['IN_PROGRESS']->count() }}
                            </span>
                        </div>

                        <button aria-label="Jarayondagi ustun menyusi" class="kanban-column__menu" type="button">
                            ⋯
                        </button>

                    </header>


                    <div class="kanban-column__tasks" data-kanban-tasks="IN_PROGRESS">

                        @forelse ($tasksByStatus['IN_PROGRESS'] as $task)

                        <article class="task-card" data-task-id="{{ $task->id }}" role="button" tabindex="0">

                            <header class="task-card__meta">

                                <span class="task-card__number">
                                    {{ $task->task_number ?? 'TASK-' . $task->id }}
                                </span>

                                <button aria-label="Topshiriq menyusi" class="task-card__menu" type="button">
                                    ⋯
                                </button>

                            </header>


                            <h3 class="task-card__title">
                                {{ $task->title }}
                            </h3>


                            @if ($task->description)
                            <p class="task-card__description">
                                {{ $task->description }}
                            </p>
                            @endif


                            @if ($task->sprints->isNotEmpty())
                            <div class="task-card__labels">

                                @foreach ($task->sprints as $sprint)
                                <span class="task-label">
                                    {{ $sprint->name }}
                                </span>
                                @endforeach

                            </div>
                            @endif


                            <footer class="task-card__footer">

                                <span class="task-card__person">

                                    <span class="avatar avatar--sm">
                                        {{ strtoupper(substr($task->assignee?->full_name ?? '?', 0, 1)) }}
                                    </span>

                                    <span>
                                        {{ $task->assignee?->full_name ?? 'Biriktirilmagan' }}
                                    </span>

                                </span>


                               @if ($task->deadline)
                                    <time datetime="{{ $task->deadline->toISOString() }}">
                                        {{ $task->deadline->format('d.m.Y H:i') }}
                                    </time>
                                @else
                                    <time>—</time>
                                @endif

                            </footer>

                        </article>

                        @empty

                        <p class="kanban-column__empty">
                            Faol topshiriqlar yo‘q
                        </p>

                        @endforelse

                    </div>

                </section>



                {{-- SUBMITTED --}}
                <section class="kanban-column" data-status="SUBMITTED">

                    <header class="kanban-column__header">

                        <div>
                            <span class="kanban-column__indicator kanban-column__indicator--review"></span>

                            <h2>Qabul kutmoqda</h2>

                            <span class="kanban-column__count" data-column-count="SUBMITTED">
                                {{ $tasksByStatus['SUBMITTED']->count() }}
                            </span>
                        </div>

                        <button aria-label="Qabul kutilayotgan ustun menyusi" class="kanban-column__menu" type="button">
                            ⋯
                        </button>

                    </header>


                    <div class="kanban-column__tasks" data-kanban-tasks="SUBMITTED">

                        @forelse ($tasksByStatus['SUBMITTED'] as $task)

                        <article class="task-card" data-task-id="{{ $task->id }}" role="button" tabindex="0">

                            <header class="task-card__meta">

                                <span class="task-card__number">
                                    {{ $task->task_number ?? 'TASK-' . $task->id }}
                                </span>

                                <button aria-label="Topshiriq menyusi" class="task-card__menu" type="button">
                                    ⋯
                                </button>

                            </header>


                            <h3 class="task-card__title">
                                {{ $task->title }}
                            </h3>


                            @if ($task->description)
                            <p class="task-card__description">
                                {{ $task->description }}
                            </p>
                            @endif


                            @if ($task->sprints->isNotEmpty())
                            <div class="task-card__labels">

                                @foreach ($task->sprints as $sprint)
                                <span class="task-label">
                                    {{ $sprint->name }}
                                </span>
                                @endforeach

                            </div>
                            @endif


                            <footer class="task-card__footer">

                                <span class="task-card__person">

                                    <span class="avatar avatar--sm">
                                        {{ strtoupper(substr($task->assignee?->full_name ?? '?', 0, 1)) }}
                                    </span>

                                    <span>
                                        {{ $task->assignee?->full_name ?? 'Biriktirilmagan' }}
                                    </span>

                                </span>


                              @if ($task->deadline)
                                    <time datetime="{{ $task->deadline->toISOString() }}">
                                        {{ $task->deadline->format('d.m.Y H:i') }}
                                    </time>
                                @else
                                    <time>—</time>
                                @endif

                            </footer>

                        </article>

                        @empty

                        <p class="kanban-column__empty">
                            Qabul kutilayotgan topshiriqlar yo‘q
                        </p>

                        @endforelse

                    </div>

                </section>

                {{-- APPROVED --}}
                <section class="kanban-column" data-status="APPROVED">

                    <header class="kanban-column__header">

                        <div>
                            <span class="kanban-column__indicator kanban-column__indicator--done"></span>

                            <h2>Tasdiqlandi</h2>

                            <span class="kanban-column__count" data-column-count="APPROVED">
                                {{ $tasksByStatus['APPROVED']->count() }}
                            </span>
                        </div>

                        <button aria-label="Tasdiqlangan ustun menyusi" class="kanban-column__menu" type="button">
                            ⋯
                        </button>

                    </header>


                    <div class="kanban-column__tasks" data-kanban-tasks="APPROVED">

                        @forelse ($tasksByStatus['APPROVED'] as $task)

                        <article class="task-card" data-task-id="{{ $task->id }}" role="button" tabindex="0">

                            <header class="task-card__meta">

                                <span class="task-card__number">
                                    {{ $task->task_number ?? 'TASK-' . $task->id }}
                                </span>

                                <button aria-label="Topshiriq menyusi" class="task-card__menu" type="button">
                                    ⋯
                                </button>

                            </header>


                            <h3 class="task-card__title">
                                {{ $task->title }}
                            </h3>


                            @if ($task->description)
                            <p class="task-card__description">
                                {{ $task->description }}
                            </p>
                            @endif


                            @if ($task->sprints->isNotEmpty())
                            <div class="task-card__labels">

                                @foreach ($task->sprints as $sprint)
                                <span class="task-label">
                                    {{ $sprint->name }}
                                </span>
                                @endforeach

                            </div>
                            @endif


                            <footer class="task-card__footer">

                                <span class="task-card__person">

                                    <span class="avatar avatar--sm">
                                        {{ strtoupper(substr($task->assignee?->full_name ?? '?', 0, 1)) }}
                                    </span>

                                    <span>
                                        {{ $task->assignee?->full_name ?? 'Biriktirilmagan' }}
                                    </span>

                                </span>


                               @if ($task->deadline)
                                    <time datetime="{{ $task->deadline->toISOString() }}">
                                        {{ $task->deadline->format('d.m.Y H:i') }}
                                    </time>
                                @else
                                    <time>—</time>
                                @endif

                            </footer>

                        </article>

                        @empty

                        <p class="kanban-column__empty">
                            Tasdiqlangan topshiriqlar yo‘q
                        </p>

                        @endforelse

                    </div>

                </section>



                {{-- CLOSED --}}
                <section class="kanban-column" data-status="CLOSED">

                    <header class="kanban-column__header">

                        <div>
                            <span class="kanban-column__indicator kanban-column__indicator--closed"></span>

                            <h2>Yopildi</h2>

                            <span class="kanban-column__count" data-column-count="CLOSED">
                                {{ $tasksByStatus['CLOSED']->count() }}
                            </span>
                        </div>

                        <button aria-label="Yopilgan ustun menyusi" class="kanban-column__menu" type="button">
                            ⋯
                        </button>

                    </header>


                    <div class="kanban-column__tasks" data-kanban-tasks="CLOSED">

                        @forelse ($tasksByStatus['CLOSED'] as $task)

                        <article class="task-card" data-task-id="{{ $task->id }}" role="button" tabindex="0">

                            <header class="task-card__meta">

                                <span class="task-card__number">
                                    {{ $task->task_number ?? 'TASK-' . $task->id }}
                                </span>

                                <button aria-label="Topshiriq menyusi" class="task-card__menu" type="button">
                                    ⋯
                                </button>

                            </header>


                            <h3 class="task-card__title">
                                {{ $task->title }}
                            </h3>


                            @if ($task->description)
                            <p class="task-card__description">
                                {{ $task->description }}
                            </p>
                            @endif


                            @if ($task->sprints->isNotEmpty())
                            <div class="task-card__labels">

                                @foreach ($task->sprints as $sprint)
                                <span class="task-label">
                                    {{ $sprint->name }}
                                </span>
                                @endforeach

                            </div>
                            @endif


                            <footer class="task-card__footer">

                                <span class="task-card__person">

                                    <span class="avatar avatar--sm">
                                        {{ strtoupper(substr($task->assignee?->full_name ?? '?', 0, 1)) }}
                                    </span>

                                    <span>
                                        {{ $task->assignee?->full_name ?? 'Biriktirilmagan' }}
                                    </span>

                                </span>


                               @if ($task->deadline)
                                    <time datetime="{{ $task->deadline->toISOString() }}">
                                        {{ $task->deadline->format('d.m.Y H:i') }}
                                    </time>
                                @else
                                    <time>—</time>
                                @endif

                            </footer>

                        </article>

                        @empty

                        <p class="kanban-column__empty">
                            Yopilgan topshiriqlar yo‘q
                        </p>

                        @endforelse

                    </div>

                </section>

            </section>
            <section aria-label="Topshiriqlar jadvali" class="tasks-view tasks-view--table" hidden="" id="table-view">
                <div class="tasks-table-wrap card">
                    <table class="tasks-table">
                        <thead>
                            <tr>
                                <th scope="col">Topshiriq</th>
                                <th scope="col">Holat</th>
                                <th scope="col">Ustuvorlik</th>
                                <th scope="col">Ijrochi</th>
                                <th scope="col">Muallif</th>
                                <th scope="col">Muddat</th>
                                <th scope="col">Yaratilgan</th>
                            </tr>
                        </thead>
                        <tbody data-repeat="tasks.paginated" id="tasks-table-body"></tbody>
                    </table>
                </div>
                <footer aria-label="Jadval sahifalash" class="table-pagination">
                    <span class="table-pagination__summary" data-table-summary="">0–0 / 0</span>
                    <div class="table-pagination__controls">
                        <button aria-label="Oldingi sahifa" class="pagination-button" data-action="table-prev"
                            type="button">‹</button>
                        <div class="table-pagination__pages" data-table-pages=""></div>
                        <button aria-label="Keyingi sahifa" class="pagination-button" data-action="table-next"
                            type="button">›</button>
                    </div>
                </footer>
            </section>
            <p class="empty-state" hidden="" id="tasks-empty-state">Joriy filtrlar bo‘yicha topshiriqlar
                topilmadi.</p>
        </section>
    </section>
</main>
@endsection

@section('overlays')
<div class="task-detail-backdrop" data-action="close-task" hidden="" id="task-detail-backdrop"></div>
<aside aria-labelledby="task-detail-title" aria-live="polite" aria-modal="true" class="task-detail-panel" hidden=""
    id="task-detail-panel" role="dialog">
    <header class="task-detail-panel__header">
        <div class="task-detail-heading">
            <p class="task-detail-panel__number"><span data-task-detail="number"></span><span>·</span><span
                    data-task-detail="group"></span></p>
            <div class="task-detail-chips"><span class="status-badge" data-task-detail="status"></span><span
                    class="priority-badge" data-task-detail="priority"></span><span class="confidence-badge"
                    data-task-detail="confidence"></span></div>
            <h2 data-task-detail="title" id="task-detail-title"></h2>
        </div>
        <button aria-label="Topshiriq tafsilotlarini yopish" class="icon-button task-detail-close"
            data-action="close-task" type="button">×</button>
    </header>
    <section aria-label="Topshiriq jarayoni" class="task-status-line">
        <div class="task-status-line__track"></div>
        <ol class="task-status-line__steps">

    <li data-status-step="created">
        <span class="task-status-line__dot">✓</span>
        <strong>Yaratildi</strong>
        <time data-status-time="created">—</time>
    </li>

    <li data-status-step="assigned">
        <span class="task-status-line__dot">•</span>
        <strong>Biriktirildi</strong>
        <time data-status-time="assigned">—</time>
    </li>

    <li data-status-step="accepted">
        <span class="task-status-line__dot">•</span>
        <strong>Qabul qilindi</strong>
        <time data-status-time="accepted">—</time>
    </li>

    <li data-status-step="in_progress">
        <span class="task-status-line__dot">•</span>
        <strong>Jarayonda</strong>
        <time data-status-time="in_progress">—</time>
    </li>

    <li data-status-step="awaiting_acceptance">
        <span class="task-status-line__dot">•</span>
        <strong>Qabul kutmoqda</strong>
        <time data-status-time="awaiting_acceptance">—</time>
    </li>

    <li data-status-step="completion_approved">
        <span class="task-status-line__dot">•</span>
        <strong>Tasdiqlandi</strong>
        <time data-status-time="completion_approved">—</time>
    </li>

    <li data-status-step="closed">
        <span class="task-status-line__dot">•</span>
        <strong>Yopildi</strong>
        <time data-status-time="closed">—</time>
    </li>

</ol>
    </section>
    <nav aria-label="Topshiriq tafsilotlari bo‘limlari" class="task-detail-tabs" role="tablist">
        <button aria-selected="true" class="task-detail-tab is-active" data-action="detail-tab"
            data-detail-tab="details" type="button">Tafsilotlar</button>
        <button aria-selected="false" class="task-detail-tab" data-action="detail-tab" data-detail-tab="chain"
            type="button">Zanjir</button>
        <button aria-selected="false" class="task-detail-tab" data-action="detail-tab" data-detail-tab="comments"
            type="button">Izohlar <span data-task-detail="comment-count">0</span></button>
        <button aria-selected="false" class="task-detail-tab" data-action="detail-tab" data-detail-tab="history"
            type="button">Tarix <span data-task-detail="history-count">0</span></button>
    </nav>
    <div class="task-detail-panel__body">
        <section class="task-detail-tab-panel is-active" data-detail-panel="details">
            <div class="task-detail-layout">
                <div class="task-detail-main-column">
                    <section class="task-detail-card task-source-card">
                        <header>
                            <h3>↗ Manba xabar</h3><span class="source-kind"
                                data-task-detail="source-kind"></span><button class="text-action" type="button">↗
                                Telegramda ochish</button>
                        </header>
                        <blockquote data-task-detail="source-text"></blockquote>
                        <footer class="source-meta"><span class="avatar avatar--sm"
                                data-task-detail="creator-initials"></span><span data-task-detail="creator"></span><time
                                data-task-detail="created-at"></time><span>Ajratish aniqligi <strong
                                    data-task-detail="confidence-value"></strong></span></footer>
                    </section>
                    <section class="task-detail-card task-detail-description-card">
                        <h3>Tavsif</h3>
                        <p data-task-detail="description"></p>
                    </section>
                    <section class="task-detail-card checklist-card">
                        <header>
                            <h3>Bajarilish punktlari</h3><strong><span data-task-detail="checklist-done">0</span>/<span
                                    data-task-detail="checklist-total">0</span></strong>
                        </header>
                        <div class="checklist-list" data-task-checklist=""></div>
                        <p class="checklist-empty" data-task-checklist-empty="" hidden="">Punktlar yo‘q</p>
                        <div class="checklist-add"><input aria-label="Yangi punkt" placeholder="Yangi punkt qo‘shish…"
                                type="text" /><button aria-label="Punkt qo‘shish" class="icon-button"
                                type="button">＋</button></div>
                    </section>
                </div>
                <aside class="task-detail-side-column">
                    <section class="deadline-card" data-deadline-card="">
                        <div class="deadline-card__icon">◷</div>
                        <div>
                            <p data-task-detail="deadline-label">Muddat belgilanmagan</p><strong
                                data-task-detail="deadline-value">SLA hisoblanmaydi</strong>
                        </div>
                        {{-- <button class="btn btn--ghost deadline-card__button" type="button">＋2 kun muddat
                            qo‘shish</button> --}}
                    </section>
                    <dl class="task-detail-list task-detail-list--compact">
                        <div>
                            <dt>Holat</dt>
                            <dd><span class="status-badge" data-task-detail="status-side"></span></dd>
                        </div>
                        <div>
                            <dt>Ijrochi</dt>
                            <dd data-task-detail="assignee"></dd>
                        </div>
                        <div>
                            <dt>Ustuvorlik</dt>
                            <dd><span class="priority-badge" data-task-detail="priority-side"></span></dd>
                        </div>
                        <div>
                            <dt>Muddat</dt>
                            <dd><time data-task-detail="due-date"></time></dd>
                        </div>
                        <div>
                            <dt>Muallif</dt>
                            <dd><span class="detail-person"><span class="avatar avatar--sm"
                                        data-task-detail="creator-side-initials"></span><span
                                        data-task-detail="creator-side"></span></span></dd>
                        </div>
                        <div>
                            <dt>Yaratilgan</dt>
                            <dd><time data-task-detail="created-at-side"></time></dd>
                        </div>
                    </dl>
                    {{-- <div class="task-detail-actions">
                        @can('task.update')
                        <button class="btn btn--primary" data-action="start-task" type="button">Ishni
                            boshlash</button>
                        @endcan
                        @can('task.cancel')
                        <button class="btn btn--ghost" data-action="cancel-assignment" type="button">Biriktirishni
                            bekor qilish</button>
                        @endcan
                        @can('task.archive')
                        <button class="btn btn--danger" data-action="archive-task" type="button">Topshiriqni
                            arxivlash</button>
                        @endcan
                    </div> --}}
                </aside>
            </div>
        </section>
        <section class="task-detail-tab-panel" data-detail-panel="chain" hidden="">
            <div class="task-detail-card task-chain-card">
                <h3>Zanjir</h3>
                <p class="muted" data-task-chain-summary="">Ushbu topshiriq mustaqil topshiriq.</p>
                <div class="task-chain-node"><span>◉</span><strong data-task-detail="title-chain"></strong><small
                        data-task-detail="number-chain"></small></div>
            </div>
        </section>
        <section class="task-detail-tab-panel" data-detail-panel="comments" hidden="">
            <div class="task-detail-card comments-card">
                <header>
                    <h3>Izohlar</h3><span class="count-pill" data-task-detail="comment-count-side">0</span>
                </header>
                <div class="comments-list" data-repeat="task.comments" data-task-comments=""></div>
                <p class="comments-empty" data-task-comments-empty="">Hozircha izohlar yo‘q.</p>
                <form class="comment-composer" data-action="comment-form"><textarea aria-label="Izoh"
                        placeholder="Izoh yozing…"></textarea><button class="btn btn--primary"
                        type="submit">Yuborish</button></form>
            </div>
        </section>
        <section class="task-detail-tab-panel" data-detail-panel="history" hidden="">
            <div class="task-detail-card history-card">
                <header>
                    <h3>Tarix va loglar</h3><span class="count-pill" data-task-detail="history-count-side">0</span>
                </header>
                <ol class="history-list" data-repeat="task.activity" data-task-history=""></ol>
            </div>
        </section>
    </div>
</aside>
@can('task.create')
<div class="modal-backdrop" hidden id="create-task-modal">
    <section aria-labelledby="create-task-modal-title" aria-modal="true" class="person-profile-modal card" role="dialog">
        <header class="person-profile-modal__header">
            <h2 id="create-task-modal-title">Yangi topshiriq</h2>
            <button aria-label="Yopish" class="modal-close" data-action="close-create-task" type="button">×</button>
        </header>
        <form id="create-task-form">
            <div class="person-profile-modal__body">
                <div class="form-error-list" hidden id="create-task-errors"></div>
                <label class="field">
                    <span>Sarlavha</span>
                    <input id="create-task-title" maxlength="500" name="title" required type="text">
                </label>
                <label class="field">
                    <span>Tavsif</span>
                    <textarea id="create-task-description" name="description" rows="3"></textarea>
                </label>
                <label class="field">
                    <span>Ijrochi</span>
                    <select id="create-task-assignee" name="assignee_id" required>
                        <option disabled selected value="">Ijrochini tanlang</option>
                        @foreach ($staff as $person)
                        <option value="{{ $person->id }}">{{ $person->full_name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field">
                    <span>Ustuvorlik</span>
                    <select id="create-task-priority" name="priority">
                        <option value="low">Past</option>
                        <option selected value="normal">Oddiy</option>
                        <option value="high">Yuqori</option>
                        <option value="urgent">Juda yuqori</option>
                    </select>
                </label>
                <label class="field">
                    <span>Muddat</span>
                    <input id="create-task-deadline" name="deadline" type="datetime-local">
                </label>
            </div>
            <footer class="person-profile-modal__footer">
                <button class="btn btn--ghost" data-action="close-create-task" type="button">Bekor qilish</button>
                <button class="btn btn--primary" type="submit">Yaratish</button>
            </footer>
        </form>
    </section>
</div>
@endcan
<template id="task-card-template">
    <article class="task-card" data-task-id="" role="button" tabindex="0">
        <header class="task-card__meta"><span class="task-card__number" data-task-field="number"></span><button
                aria-label="Topshiriq menyusi" class="task-card__menu" type="button">⋯</button></header>
        <h3 class="task-card__title" data-task-field="title"></h3>
        <div class="task-card__progress" data-task-progress-wrap="" hidden=""><span
                data-task-progress-bar=""></span><small data-task-progress=""></small></div>
        <div class="task-card__labels" data-task-labels=""></div>
        <footer class="task-card__footer"><span class="task-card__person"><span class="avatar avatar--sm"
                    data-task-field="assignee-initials"></span><span data-task-field="assignee"></span></span><time
                data-task-field="due-date"></time></footer>
    </article>
</template>
<template id="task-row-template">
    <tr class="task-row" data-task-id="" tabindex="0">
        <td>
            <div class="table-task"><strong data-task-field="title"></strong><span data-task-field="number"></span>
            </div>
        </td>
        <td><span class="status-badge" data-task-field="status"></span></td>
        <td><span class="priority-badge" data-task-field="priority"></span></td>
        <td><span class="table-person"><span class="avatar avatar--sm" data-task-field="assignee-initials"></span><span
                    data-task-field="assignee"></span></span></td>
        <td data-task-field="creator"></td>
        <td><time data-task-field="due-date"></time></td>
        <td><time data-task-field="created-at"></time></td>
    </tr>
</template>
<template id="filter-option-template">
    <option value=""></option>
</template>
<template id="task-label-template"><span class="task-label" data-task-label=""></span></template>
<template id="checklist-item-template"><label class="checklist-item"><input data-checklist-complete=""
            type="checkbox" /><span data-checklist-title=""></span></label></template>
<template id="history-item-template">
    <li class="history-item"><span class="history-item__dot"></span>
        <div><strong data-history-title=""></strong>
            <p data-history-description=""></p><time data-history-time=""></time>
        </div>
    </li>
</template>
<template id="pagination-page-template"><button class="pagination-page" data-action="table-page"
        type="button"></button></template>
<div aria-atomic="true" aria-live="polite" class="toast-region" id="toast-region"></div>

@php
    $tasksData = $tasks->map(function ($task) {

            return [

                'id' => $task->id,

                'number' => $task->task_number ?? ('TASK-' . $task->id),

                'title' => $task->title,

                'description' => $task->description,

                /*
                |--------------------------------------------------------------------------
                | Status
                |--------------------------------------------------------------------------
                */

                'status' => $task->status instanceof \BackedEnum
                    ? $task->status->value
                    : $task->status,


                /*
                |--------------------------------------------------------------------------
                | Priority
                |--------------------------------------------------------------------------
                */

                'priority' => $task->priority instanceof \BackedEnum
                    ? $task->priority->value
                    : $task->priority,


                /*
                |--------------------------------------------------------------------------
                | People
                |--------------------------------------------------------------------------
                */

                'assignee' => [
                    'id' => $task->assignee?->id,
                    'name' => $task->assignee?->full_name,
                ],

                'creator' => [
                    'id' => $task->author?->id,
                    'name' => $task->author?->full_name,
                ],

                'assignor' => [
                    'id' => $task->assignor?->id,
                    'name' => $task->assignor?->full_name,
                ],


                /*
                |--------------------------------------------------------------------------
                | Dates
                |--------------------------------------------------------------------------
                |
                | IMPORTANT:
                | Database column is `deadline`, not `due_at`.
                |
                */

                'dueAt' => $task->deadline
                    ? $task->deadline->toISOString()
                    : null,

                'deadline' => $task->deadline
                    ? $task->deadline->toISOString()
                    : null,

                'createdAt' => $task->created_at
                    ? $task->created_at->toISOString()
                    : null,

                'assignedAt' => $task->started_at
                    ? $task->started_at->toISOString()
                    : null,

                'startedAt' => $task->started_at
                    ? $task->started_at->toISOString()
                    : null,

                'completedAt' => $task->completed_at
                    ? $task->completed_at->toISOString()
                    : null,

                'closedAt' => $task->closed_at
                    ? $task->closed_at->toISOString()
                    : null,

                'statusDates' => [
                    'created' => optional($task->created_at)->toISOString(),

                    'assigned' => optional($task->created_at)->toISOString(),

                    'in_progress' => optional($task->started_at)->toISOString(),

                    'awaiting_acceptance' => optional($task->completed_at)->toISOString(),

                    'accepted' => optional($task->completed_at)->toISOString(),

                    'completion_approved' => optional($task->completed_at)->toISOString(),

                    'closed' => optional($task->closed_at)->toISOString(),
                ],
                /*
                |--------------------------------------------------------------------------
                | Source
                |--------------------------------------------------------------------------
                */

                'sourceType' => $task->source_type,

                'sourceMessageId' => $task->source_message_id,

                'sourceUrl' => $task->source_url,

                /*
                | Source text.
                |
                | Currently the task description is the safest fallback because
                | source_id can be null and source_type can be text/voice.
                |
                */

                'sourceText' => $task->description ?? '',

                'confidence' => $task->ajralish_aniqligi,


                /*
                |--------------------------------------------------------------------------
                | Comments
                |--------------------------------------------------------------------------
                */

                'comments' => $task->comments->map(function ($comment) {

                    return [

                        'id' => $comment->id,

                        'body' => $comment->body
                            ?? $comment->comment
                            ?? '',

                        'createdAt' => $comment->created_at
                            ? $comment->created_at->toISOString()
                            : null,

                        'staff' => [
                            'id' => $comment->staff?->id,
                            'name' => $comment->staff?->full_name,
                        ],

                    ];

                })->values()->all(),


                /*
                |--------------------------------------------------------------------------
                | Activity / Logs
                |--------------------------------------------------------------------------
                */

                'logs' => $task->logs->map(function ($log) {

                    return [

                        'id' => $log->id,

                        'createdAt' => $log->created_at
                            ? $log->created_at->toISOString()
                            : null,

                        'actor' => $log->actor?->full_name,

                        'fromAssignee' => $log->fromAssignee?->full_name,

                        'toAssignee' => $log->toAssignee?->full_name,

                        'action' => $log->action ?? '',

                        'description' => $log->description ?? '',

                    ];

                })->values()->all(),


                /*
                |--------------------------------------------------------------------------
                | Sprints
                |--------------------------------------------------------------------------
                */

                'sprints' => $task->sprints->map(function ($sprint) {

                    return [

                        'id' => $sprint->id,

                        'name' => $sprint->name,

                    ];

                })->values()->all(),

            ];

        })->values()->all()
@endphp

<script>
    window.tasksData = @json($tasksData);
</script>
@endsection
