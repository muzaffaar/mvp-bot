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
<section aria-label="Xavfsizlik bo‘limlari" class="security-cards">
<article class="card security-card security-card--password">
<header class="security-card__header">
<div aria-hidden="true" class="security-card__icon">⌘</div>
<div>
<h3>Parolni o‘zgartirish</h3>
<p>Hisobingiz uchun yangi, kuchli parol o‘rnating.</p>
</div>
</header>
<form class="settings-form" id="security-form" novalidate="">
<label class="field"><span>Joriy parol</span><input autocomplete="current-password" name="current_password" required="" type="password"/></label>
<label class="field"><span>Yangi parol</span><input autocomplete="new-password" minlength="8" name="new_password" required="" type="password"/></label>
<label class="field"><span>Yangi parolni tasdiqlang</span><input autocomplete="new-password" minlength="8" name="new_password_confirmation" required="" type="password"/></label>
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
<div aria-live="polite" class="security-logs-list" id="security-logs-list"></div>
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
<div aria-live="polite" class="sessions-list" id="sessions-list"></div>
</article>
</section>
</section>
<template id="security-log-template">
<article class="security-log-item">
<span aria-hidden="true" class="security-log-item__marker"></span>
<div class="security-log-item__main">
<strong data-field="action"></strong>
<span data-field="object"></span>
</div>
<time data-field="date"></time>
</article>
</template>
<template id="session-template">
<article class="session" data-session-id="">
<div aria-hidden="true" class="session__icon">●</div>
<div class="session__main">
<div class="session__title-row"><h3 data-field="device"></h3><span class="session__current" data-field="current" hidden="">Joriy</span></div>
<p><span data-field="location"></span> · <span data-field="ip"></span></p>
<small data-field="last-active"></small>
</div>
<button class="btn btn--ghost btn--danger" data-action="remove-session" type="button">Sessiyani o‘chirish</button>
</article>
</template>
</main>
@endsection

@section('overlays')
<div aria-atomic="true" aria-live="polite" class="toast-region" id="toast-region"></div>
@endsection
