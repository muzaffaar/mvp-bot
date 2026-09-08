@extends('layouts.admin')

@php
$page = 'dashboard';
$title = 'IMV IB Support — Boshqaruv paneli';
@endphp

@section('content')
<main class="app-content dashboard-content">
    <section aria-labelledby="dashboard-overview-title" class="dashboard-page">
        <h2 class="sr-only" id="dashboard-overview-title">Boshqaruv paneli ma'lumotlari</h2>
        <section aria-label="Asosiy ko'rsatkichlar" class="dashboard-metrics">

            <article class="dashboard-metric-card">

                <p class="dashboard-metric-card__label">
                    Jami topshiriq
                </p>

                <strong class="dashboard-metric-card__value">
                    {{ number_format($stats['total'] ?? 0) }}
                </strong>

                <div class="dashboard-metric-card__footer">

                    <span>jami</span>

                </div>

            </article>


            <article class="dashboard-metric-card">

                <p class="dashboard-metric-card__label">
                    O'z vaqtida bajarish
                </p>

                <strong class="dashboard-metric-card__value">

                    {{ $stats['on_time_percentage'] ?? 0 }}

                    <small>%</small>

                </strong>

                <div class="dashboard-metric-card__footer">

                    <span>bajarilgan topshiriqlar</span>

                </div>

            </article>


            <article class="dashboard-metric-card">

                <p class="dashboard-metric-card__label">
                    Bajarish medianasi
                </p>

                <strong class="dashboard-metric-card__value">

                    {{ $stats['median_hours'] ?? 0 }}

                    <small>soat</small>

                </strong>

                <div class="dashboard-metric-card__footer">

                    <span>bajarilgan topshiriqlar</span>

                </div>

            </article>


            <article class="dashboard-metric-card">

                <p class="dashboard-metric-card__label">
                    Muddati o'tgan
                </p>

                <strong class="dashboard-metric-card__value">

                    {{ $stats['overdue'] ?? 0 }}

                </strong>

                <div class="dashboard-metric-card__footer">

                    <span>hozirda</span>

                    @if(($stats['overdue'] ?? 0) > 0)

                    <span class="metric-alert">
                        diqqat
                    </span>

                    @endif

                </div>

            </article>


            <article class="dashboard-metric-card">

                <p class="dashboard-metric-card__label">
                    Qaytarilgan ijro
                </p>

                <strong class="dashboard-metric-card__value">

                    {{ $stats['returned_percentage'] ?? 0 }}

                    <small>%</small>

                </strong>

                <div class="dashboard-metric-card__footer">

                    <span>jami topshiriqlardan</span>

                </div>

            </article>

        </section>
        <section aria-label="Topshiriqlar analitikasi" class="dashboard-analytics-grid">
            <article class="card dashboard-chart-card">
                <header class="dashboard-section-header">
                    <div>
                        <h2>Topshiriqlar dinamikasi</h2>
                    </div>
                    <div aria-label="Grafik afsonasi" class="chart-legend">
                        <span><i class="legend-marker legend-marker--opened"></i>Ochilgan</span>
                        <span><i class="legend-marker legend-marker--accepted"></i>Qabul qilingan</span>
                    </div>
                </header>
                <figure
                    class="dashboard-chart"
                    data-chart="task-dynamics"
                    data-dynamics='@json($stats["dynamics"] ?? [])'
                >
                    <svg
                        aria-label="Topshiriqlar dinamikasi"
                        role="img"
                        viewBox="0 0 920 270"
                        preserveAspectRatio="none"
                    ></svg>

                    <figcaption class="sr-only">
                        Ochilgan va qabul qilingan topshiriqlar dinamikasi
                    </figcaption>
                </figure>
            </article>
            <article class="card dashboard-status-card">
                <header class="dashboard-section-header">
                    <h2>Holatlar bo'yicha</h2>
                </header>
                <div aria-label="{{ $stats['total'] ?? 0 }} topshiriq holatlar bo'yicha taqsimlangan"
                    class="status-donut"  id="dashboard-status-donut">

                    <div class="status-donut__center">

                        <strong>

                            {{ $stats['total'] ?? 0 }}

                        </strong>

                        <span>
                            topshiriq
                        </span>

                    </div>

                </div>
                <ul class="status-breakdown">

                    <li>

                        <span>

                            <i class="status-dot status-dot--new"></i>

                            Yaratildi

                        </span>

                        <strong>
                            {{ $stats['status_distribution']['created'] ?? 0 }}
                        </strong>

                    </li>


                    <li>

                        <span>

                            <i class="status-dot status-dot--assigned"></i>

                            Biriktirildi

                        </span>

                        <strong>
                            {{ $stats['status_distribution']['assigned'] ?? 0 }}
                        </strong>

                    </li>


                    <li>

                        <span>

                            <i class="status-dot status-dot--paused"></i>

                            Jarayonda

                        </span>

                        <strong>
                            {{ $stats['status_distribution']['in_progress'] ?? 0 }}
                        </strong>

                    </li>


                    <li>

                        <span>

                            <i class="status-dot status-dot--review"></i>

                            Qabul kutmoqda

                        </span>

                        <strong>
                            {{ $stats['status_distribution']['awaiting_acceptance'] ?? 0 }}
                        </strong>

                    </li>


                    <li>

                        <span>

                            <i class="status-dot status-dot--accepted"></i>

                            Qabul qilindi / Tasdiqlandi

                        </span>

                        <strong>
                            {{ $stats['status_distribution']['accepted'] ?? 0 }}
                        </strong>

                    </li>


                    <li>

                        <span>

                            <i class="status-dot status-dot--closed"></i>

                            Yopildi

                        </span>

                        <strong>
                            {{ $stats['status_distribution']['closed'] ?? 0 }}
                        </strong>

                    </li>


                    <li>

                        <span>

                            <i class="status-dot status-dot--returned"></i>

                            Qaytarildi

                        </span>

                        <strong>
                            {{ $stats['status_distribution']['returned'] ?? 0 }}
                        </strong>

                    </li>


                    <li>

                        <span>

                            <i class="status-dot status-dot--danger"></i>

                            Bekor qilindi

                        </span>

                        <strong>
                            {{ $stats['status_distribution']['cancelled'] ?? 0 }}
                        </strong>

                    </li>

                </ul>
            </article>
        </section>
        <section aria-label="Diqqat talab qiladigan topshiriqlar" class="dashboard-attention-grid">
            <article class="card attention-card" data-section="pending-acceptance">

                <header class="attention-card__header">

                    <h2>
                        Qabulingizni kutmoqda
                    </h2>

                    <span class="attention-count attention-count--info">

                        {{ count($stats['waiting_acceptance'] ?? []) }}

                    </span>

                </header>


                <ul class="attention-list">

                    @forelse($stats['waiting_acceptance'] ?? [] as $task)

                    <li class="attention-task">

                        <span class="task-avatar task-avatar--purple">

                            {{ mb_strtoupper(mb_substr($task['assignee'] ?? '?', 0, 1)) }}

                        </span>


                        <div class="attention-task__content">

                            <a href="{{ route('tasks.index') }}">

                                {{ $task['title'] }}

                            </a>


                            <p>

                                {{ $task['number'] }}

                                ·

                                {{ $task['assignee'] ?? 'biriktirilmagan' }}

                            </p>

                        </div>


                        @if($task['deadline'])

                        <time datetime="{{ $task['deadline'] }}">

                            {{ \Carbon\Carbon::parse($task['deadline'])->diffForHumans() }}

                        </time>

                        @else

                        <time>—</time>

                        @endif

                    </li>

                    @empty

                    <li class="attention-task">

                        <div class="attention-task__content">

                            <p>
                                Hozirda qabul kutayotgan topshiriqlar yo'q.
                            </p>

                        </div>

                    </li>

                    @endforelse

                </ul>


                <a class="attention-card__link" href="{{ route('tasks.index') }}">

                    Barchasini ko'rish →

                </a>

            </article>
            <article class="card attention-card" data-section="deadline-risk">
                <header class="attention-card__header">
                    <h2>Muddat xavfi ostida</h2><span class="attention-count attention-count--warning">

                        {{ count($stats['deadline_risk'] ?? []) }}

                    </span>
                </header>
                <ul class="attention-list">

                    @forelse($stats['deadline_risk'] ?? [] as $task)

                    @php

                    $deadline = $task['deadline']
                    ? \Carbon\Carbon::parse($task['deadline'])
                    : null;

                    $isOverdue = $deadline
                    ? $deadline->isPast()
                    : false;

                    @endphp


                    <li class="attention-task">

                        <span class="task-avatar task-avatar--orange">

                            {{ mb_strtoupper(mb_substr($task['assignee'] ?? '?', 0, 1)) }}

                        </span>


                        <div class="attention-task__content">

                            <a href="{{ route('tasks.index') }}">

                                {{ $task['title'] }}

                            </a>


                            <p>

                                {{ $task['number'] }}

                                ·

                                {{ $task['assignee'] ?? 'biriktirilmagan' }}

                            </p>

                        </div>


                        @if($deadline)

                        <time class="{{ $isOverdue ? 'is-danger' : 'is-warning' }}"
                            datetime="{{ $deadline->toISOString() }}">

                            {{ $isOverdue
                            ? "muddat o'tdi"
                            : $deadline->diffForHumans()
                            }}

                        </time>

                        @else

                        <time>—</time>

                        @endif

                    </li>

                    @empty

                    <li class="attention-task">

                        <div class="attention-task__content">

                            <p>
                                Muddat xavfi ostidagi topshiriqlar yo'q.
                            </p>

                        </div>

                    </li>

                    @endforelse

                </ul>
                <a class="attention-card__link" href="{{ route('tasks.index') }}">Barchasini ko'rish →</a>
            </article>
            <article class="card attention-card" data-section="unassigned">
                <header class="attention-card__header">
                    <h2>Biriktirilmagan</h2><span class="attention-count attention-count--danger">

                        {{ count($stats['unassigned'] ?? []) }}

                    </span>
                </header>

                <a class="attention-card__link" href="{{ route('tasks.index') }}">Barchasini ko'rish →</a>
            </article>
        </section>
        <section class="dashboard-bottom-grid">
            <article class="card workload-card">
                <header class="dashboard-section-header dashboard-section-header--compact">
                    <div>
                        <h2>Xodimlar yuklamasi</h2>
                    </div>
                    <p>faol topshiriqlar / me'yor 8</p>
                </header>
                <ul class="workload-list">

                    @forelse($stats['workload'] ?? [] as $member)

                    @php

                    $capacity = $member['capacity'] ?? 8;

                    $load = $member['load'] ?? 0;

                    $percentage = $capacity > 0
                    ? min(
                    100,
                    ($load / $capacity) * 100
                    )
                    : 0;

                    @endphp


                    <li>

                        <span class="person-avatar">

                            {{ mb_strtoupper(
                            mb_substr(
                            $member['name'] ?? '?',
                            0,
                            1
                            )
                            ) }}

                        </span>


                        <strong>

                            {{ $member['name'] }}

                        </strong>


                        <div class="progress-track">

                            <span style="width: {{ $percentage }}%"></span>

                        </div>


                        <b>

                            {{ $load }}/{{ $capacity }}

                        </b>

                    </li>

                    @empty

                    <li>

                        <strong>
                            Xodimlar topilmadi.
                        </strong>

                    </li>

                    @endforelse

                </ul>
            </article>
            <article class="card source-card">
                <header class="dashboard-section-header">
                    <h2>Topshiriq manbalari</h2>
                </header>
                @php

                $sources = $stats['sources'] ?? [];

                $sourceTotal = array_sum($sources);

                $sourcePercentage = function ($count) use ($sourceTotal) {

                if ($sourceTotal <= 0) { return 0; } return round( ($count / $sourceTotal) * 100 ); }; @endphp <ul
                    class="source-list">


                    <li>

                        <span class="source-list__label">

                            <i>➤</i>

                            Telegram — matn

                        </span>


                        <strong>

                            {{ $sources['telegram_text'] ?? 0 }}

                        </strong>


                        <div class="source-meter">

                            <span class="source-meter__fill source-meter__fill--blue" style="
                    width:
                    {{ $sourcePercentage(
                        $sources['telegram_text'] ?? 0
                    ) }}%
                "></span>

                        </div>

                    </li>


                    <li>

                        <span class="source-list__label">

                            <i>♬</i>

                            Telegram — ovozli

                        </span>


                        <strong>

                            {{ $sources['telegram_voice'] ?? 0 }}

                        </strong>


                        <div class="source-meter">

                            <span class="source-meter__fill source-meter__fill--purple" style="
                    width:
                    {{ $sourcePercentage(
                        $sources['telegram_voice'] ?? 0
                    ) }}%
                "></span>

                        </div>

                    </li>


                    <li>

                        <span class="source-list__label">

                            <i>▧</i>

                            Telegram — fayl/rasm

                        </span>


                        <strong>

                            {{ $sources['telegram_file'] ?? 0 }}

                        </strong>


                        <div class="source-meter">

                            <span class="source-meter__fill source-meter__fill--orange" style="
                    width:
                    {{ $sourcePercentage(
                        $sources['telegram_file'] ?? 0
                    ) }}%
                "></span>

                        </div>

                    </li>


                    <li>

                        <span class="source-list__label">

                            <i>＋</i>

                            Panelda qo'lda

                        </span>


                        <strong>

                            {{ $sources['panel'] ?? 0 }}

                        </strong>


                        <div class="source-meter">

                            <span class="source-meter__fill source-meter__fill--green" style="
                    width:
                    {{ $sourcePercentage(
                        $sources['panel'] ?? 0
                    ) }}%
                "></span>

                        </div>

                    </li>

                    </ul>
                    <footer class="source-card__footer"><span>✓ Avtomatik biriktirish
                            aniqligi</span><strong>94%</strong>
                    </footer>
            </article>
        </section>
        <section aria-label="Qo'shimcha ma'lumotlar" class="dashboard-extra-grid">
            <article class="card dashboard-summary-card">
                <header class="dashboard-section-header">
                    <h2>Bugungi faollik</h2><a href="#">Hisobot →</a>
                </header>
                <dl class="activity-summary">


                    <div>

                        <dt>
                            Yangi topshiriqlar
                        </dt>

                        <dd>

                            {{ $stats['today']['created'] ?? 0 }}

                        </dd>

                    </div>


                    <div>

                        <dt>
                            Yakunlangan
                        </dt>

                        <dd>

                            {{ $stats['today']['completed'] ?? 0 }}

                        </dd>

                    </div>


                    <div>

                        <dt>
                            Izohlar
                        </dt>

                        <dd>

                            {{ $stats['today']['comments'] ?? 0 }}

                        </dd>

                    </div>


                    <div>

                        <dt>
                            O'rtacha javob
                        </dt>

                        <dd>

                            {{ $stats['today']['average_response_minutes'] ?? 0 }} min

                        </dd>

                    </div>


                </dl>
            </article>
            <article class="card dashboard-summary-card">
                <header class="dashboard-section-header">
                    <h2>Tezkor holat</h2>
                </header>
                <ul class="quick-status-list">


                    <li>

                        <span>

                            <i class="status-dot status-dot--online"></i>

                            Telegram integratsiyasi

                        </span>


                        <strong>

                            {{ ($stats['quick_status']['telegram_active'] ?? false)
                            ? 'Faol'
                            : 'Nofaol'
                            }}

                        </strong>

                    </li>


                    <li>

                        <span>

                            <i class="status-dot status-dot--online"></i>

                            Avtomatik biriktirish

                        </span>


                        <strong>

                            {{ $stats['quick_status']['automation_accuracy'] ?? 0 }}%

                        </strong>

                    </li>


                    <li>

                        <span>

                            <i class="status-dot status-dot--warning"></i>

                            Ko'rib chiqilishi kerak

                        </span>


                        <strong>

                            {{ $stats['quick_status']['needs_review'] ?? 0 }}

                        </strong>

                    </li>


                    <li>

                        <span>

                            <i class="status-dot status-dot--danger"></i>

                            Muddati o'tgan

                        </span>


                        <strong>

                            {{ $stats['quick_status']['overdue'] ?? 0 }}

                        </strong>

                    </li>


                </ul>
            </article>
        </section>
    </section>
</main>
<script>
    window.dashboardData = @json([
        'dynamics' => $stats['dynamics'] ?? [],
        'statusDistribution' => $stats['status_distribution'] ?? [],
    ]);
</script>
@endsection
