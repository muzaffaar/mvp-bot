@extends('layouts.admin')

@php
    $page = 'security';
    $title = 'IMV IB Support — Xavfsizlik';
@endphp

@section('content')
<main class="app-content">
<section aria-labelledby="security-heading" class="page-section security-page">
<header class="page-toolbar security-page__header">
<div>
<p class="eyebrow">Hisob himoyasi</p>
<h2 id="security-heading">Xavfsizlik sozlamalari</h2>
<p class="muted">Muhim xavfsizlik amallari uchta asosiy bo‘limda jamlangan.</p>
</div>
</header>

@if (session('security_success'))
<div class="alert alert--success" role="status">{{ session('security_success') }}</div>
@endif

@if (session('security_error'))
<div class="alert alert--error" role="alert">{{ session('security_error') }}</div>
@endif

@if ($errors->any())
<div class="alert alert--error" role="alert">
    <ul>
        @foreach ($errors->all() as $error)
        <li>{{ $error }}</li>
        @endforeach
    </ul>
</div>
@endif

<section aria-label="Xavfsizlik bo‘limlari" class="security-cards">
<article class="card security-card security-card--password">
<header class="security-card__header">
<div aria-hidden="true" class="security-card__icon">⌘</div>
<div>
<h3>Parolni o‘zgartirish</h3>
<p>Hisobingiz uchun yangi, kuchli parol o‘rnating.</p>
</div>
</header>
<form class="settings-form" method="POST" action="{{ route('security.password.update') }}">
@csrf
@method('PUT')
<label class="field"><span>Joriy parol</span><input autocomplete="current-password" name="current_password" required type="password"></label>
<label class="field"><span>Yangi parol</span><input autocomplete="new-password" minlength="8" name="new_password" required type="password"></label>
<label class="field"><span>Yangi parolni tasdiqlang</span><input autocomplete="new-password" minlength="8" name="new_password_confirmation" required type="password"></label>
<button class="btn btn--primary" type="submit">Parolni yangilash</button>
</form>
</article>
<article class="card security-card security-card--logs">
<header class="security-card__header">
<div aria-hidden="true" class="security-card__icon">◷</div>
<div>
<h3>Xavfsizlik jurnali</h3>
<p>Hisob va tizim bo‘yicha so‘nggi muhim amallar.</p>
</div>
</header>
<div aria-live="polite" class="security-logs-list" id="security-logs-list">
@forelse ($securityLogs as $log)
<article class="security-log-item">
<span aria-hidden="true" class="security-log-item__marker"></span>
<div class="security-log-item__main">
<strong>{{ $log->event_type->label() }}</strong>
<span>{{ $log->description ?? ($log->ip_address ?? '—') }}</span>
</div>
<time datetime="{{ $log->created_at->toIso8601String() }}">{{ $log->created_at->diffForHumans() }}</time>
</article>
@empty
<p class="muted">Hozircha yozuvlar yo‘q.</p>
@endforelse
</div>
<a class="security-card__link" href="{{ route('reports.index') }}">To‘liq jurnalni ko‘rish →</a>
</article>
<article class="card security-card security-card--sessions">
<header class="security-card__header">
<div aria-hidden="true" class="security-card__icon">◉</div>
<div>
<h3>Faol sessiyalar</h3>
<p>Hisobingizga kirilgan qurilmalarni nazorat qiling.</p>
</div>
</header>
<div aria-live="polite" class="sessions-list" id="sessions-list">
@forelse ($sessions as $session)
<article class="session" data-session-id="{{ $session['id'] }}">
<div aria-hidden="true" class="session__icon">●</div>
<div class="session__main">
<div class="session__title-row">
<h3>{{ $session['device'] }}</h3>
@if ($session['is_current'])
<span class="session__current">Joriy</span>
@endif
</div>
<p><span>{{ $session['ip_address'] ?? 'IP noma’lum' }}</span></p>
<small>{{ $session['last_active'] }}</small>
</div>
@unless ($session['is_current'])
<form method="POST" action="{{ route('security.sessions.destroy', $session['id']) }}">
@csrf
@method('DELETE')
<button class="btn btn--ghost btn--danger" type="submit">Sessiyani o‘chirish</button>
</form>
@endunless
</article>
@empty
<p class="muted">Faol sessiya topilmadi.</p>
@endforelse
</div>
</article>
</section>
</section>
</main>
@endsection

@section('overlays')
<div aria-atomic="true" aria-live="polite" class="toast-region" id="toast-region"></div>
@endsection
