@extends('layouts.admin')

@php
    $page = 'reports';
    $title = 'IMV IB Support — Hisobotlar';
@endphp

@section('content')
<main class="app-content">
<section aria-labelledby="reports-heading" class="page-section"><header class="page-toolbar"><div><p class="eyebrow">Reporting</p><h2 id="reports-heading">Workload reports</h2><p class="muted">Track completion, overdue work and status distribution.</p></div><button class="btn" data-action="refresh-reports" type="button">Refresh</button></header><section class="dashboard-kpis"><article class="metric-card"><span>Total tasks</span><strong data-report="total">0</strong><small>All work items</small></article><article class="metric-card"><span>Overdue</span><strong data-report="overdue">0</strong><small>Past deadline</small></article><article class="metric-card"><span>Unassigned</span><strong data-report="unassigned">0</strong><small>Needs ownership</small></article><article class="metric-card"><span>Completion</span><strong data-report="completion">0%</strong><small>Accepted or closed</small></article></section><section class="reports-grid"><article class="card"><header class="card-header"><h3>Status breakdown</h3></header><div class="report-status-list" id="report-status-list"></div></article><article class="card"><header class="card-header"><h3>Report notes</h3></header><ul class="report-notes"><li>Metrics are calculated from the centralized task data.</li><li>Future API integration can replace the local data source without changing page structure.</li><li>Filters can be added without duplicating report rendering logic.</li></ul></article></section></section>
<template id="report-status-template"><div class="report-status"><span class="report-status__label" data-field="status"></span><div class="report-status__meter"><span data-field="meter"></span></div><strong data-field="count"></strong></div></template>
</main>
@endsection

@section('overlays')
<div aria-atomic="true" aria-live="polite" class="toast-region" id="toast-region"></div>
@endsection
