@extends('layouts.admin')

@php
    $page = 'chain';
    $title = 'IMV IB Support — Zanjir';
@endphp

@section('content')
<main class="app-content">
<section aria-labelledby="chain-heading" class="page-section"><header class="page-toolbar"><div><p class="eyebrow">Dependencies</p><h2 id="chain-heading">Task chain</h2><p class="muted">Inspect parent-child relationships and execution order.</p></div><a class="btn" href="{{ route('tasks.index') }}">Browse tasks</a></header><section class="chain-layout"><article class="card chain-panel"><header class="card-header"><div><h3>Dependency map</h3><p class="muted">Tasks are grouped by their relationship.</p></div></header><div class="chain-list" id="chain-list"></div></article><aside class="card chain-sidebar"><h3>How to read the chain</h3><ol class="chain-legend"><li><strong>Root</strong><span>Independent top-level work.</span></li><li><strong>Child</strong><span>Depends on another task.</span></li><li><strong>Click</strong><span>Open the task in the Tasks workspace.</span></li></ol></aside></section></section>
<template id="chain-item-template"><article class="chain-item" data-task-id="" role="button" tabindex="0"><div aria-hidden="true" class="chain-item__connector"></div><div><span class="chain-item__relation" data-field="relation"></span><h3 data-field="title"></h3><p data-field="number"></p></div><span class="status-badge" data-field="status"></span></article></template>
</main>
@endsection

@section('overlays')
<div aria-atomic="true" aria-live="polite" class="toast-region" id="toast-region"></div>
@endsection
