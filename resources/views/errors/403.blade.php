@extends('layouts.admin')

@php
    $page = $page ?? 'error';
    $title = 'IMV IB Support — Ruxsat yo‘q';
@endphp

@section('content')
<main class="app-content">
<section aria-labelledby="error-403-heading" class="page-section error-page">
<div class="error-page__card card">
<div aria-hidden="true" class="error-page__icon">🚫</div>
<p class="eyebrow">Xato 403 — Ruxsat berilmagan</p>
<h2 id="error-403-heading">Bu bo‘limga kirish huquqingiz yo‘q</h2>
<p class="muted">
    @if (! empty($exception) && method_exists($exception, 'getMessage') && $exception->getMessage())
        {{ $exception->getMessage() }}
    @else
        Sizning joriy rolingiz ushbu sahifani ko‘rish yoki amalni bajarish uchun yetarli ruxsatga ega emas.
    @endif
    Agar bu xato deb hisoblasangiz, tizim administratoriga murojaat qiling.
</p>
<a class="btn btn--primary" href="{{ route('dashboard') }}">← Boshqaruv paneliga qaytish</a>
</div>
</section>
</main>
@endsection
