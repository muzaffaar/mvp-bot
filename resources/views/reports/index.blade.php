@extends('layouts.admin')

@php
    $page = $page ?? 'reports';
    $title = $title ?? 'IMV IB Support — Hisobotlar';
@endphp

@section('content')
<main class="app-content">
<section aria-labelledby="reports-heading" class="page-section">
<header class="page-toolbar">
<div>
<p class="eyebrow">Hisobotlar</p>
<h2 id="reports-heading">Ish yuki bo‘yicha hisobot</h2>
<p class="muted">Bajarilish, muddati o‘tgan va holat bo‘yicha taqsimot.</p>
</div>
<a class="btn" href="{{ route('reports.index') }}">Yangilash</a>
</header>
<section class="dashboard-kpis">
<article class="metric-card">
<span>Jami topshiriqlar</span>
<strong>{{ $total }}</strong>
<small>Barcha ish birliklari</small>
</article>
<article class="metric-card">
<span>Muddati o‘tgan</span>
<strong>{{ $overdue }}</strong>
<small>Muddatdan kechikkan</small>
</article>
<article class="metric-card">
<span>Biriktirilmagan</span>
<strong>{{ $unassigned }}</strong>
<small>Ijrochi belgilanmagan</small>
</article>
<article class="metric-card">
<span>Bajarilish</span>
<strong>{{ $completionRate }}%</strong>
<small>Qabul qilingan yoki yopilgan</small>
</article>
</section>
<section class="reports-grid">
<article class="card">
<header class="card-header">
<h3>Holat bo‘yicha taqsimot</h3>
</header>
<div class="report-status-list">
@foreach ($statusBreakdown as $status)
<div class="report-status">
<span class="report-status__label">{{ $status['label'] }}</span>
<div class="report-status__meter"><span style="width: {{ $status['percentage'] }}%"></span></div>
<strong>{{ $status['count'] }}</strong>
</div>
@endforeach
</div>
</article>
<article class="card">
<header class="card-header">
<h3>Xodimlar bo‘yicha yuklama</h3>
</header>
<div class="report-status-list">
@forelse ($workload as $person)
<div class="report-status">
<span class="report-status__label">{{ $person->full_name }}</span>
<div class="report-status__meter"><span style="width: {{ (int) round($person->active_tasks_count / $maxWorkload * 100) }}%"></span></div>
<strong>{{ $person->active_tasks_count }}</strong>
</div>
@empty
<p class="muted">Faol xodimlar topilmadi.</p>
@endforelse
</div>
@if ($workload->contains(fn ($person) => $person->overdue_tasks_count > 0))
<p class="muted" style="margin-top: 14px;">
    Muddati o‘tgan topshiriqlari bor:
    {{ $workload->filter(fn ($person) => $person->overdue_tasks_count > 0)->map(fn ($person) => "{$person->full_name} ({$person->overdue_tasks_count})")->join(', ') }}
</p>
@endif
</article>
</section>
</section>
</main>
@endsection

@section('overlays')
<div aria-atomic="true" aria-live="polite" class="toast-region" id="toast-region"></div>
@endsection
