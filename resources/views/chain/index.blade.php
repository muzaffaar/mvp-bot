@extends('layouts.admin')

@php
    $page = 'chain';
    $title = 'IMV IB Support — Zanjir';
@endphp

@section('content')
<main class="app-content">
    <section aria-labelledby="chain-heading" class="page-section">
        <header class="page-toolbar">
            <div>
                <p class="eyebrow">Bog‘liqliklar</p>

                <h2 id="chain-heading">Topshiriqlar zanjiri</h2>

                <p class="muted">
                    Ota-bola topshiriqlar bog‘liqligi va bajarilish tartibini ko‘ring.
                </p>
            </div>

            <a class="btn" href="{{ route('tasks.index') }}">
                Topshiriqlarni ko‘rish
            </a>
        </header>

        <section class="chain-layout">
            <article class="card chain-panel">
                <header class="card-header">
                    <div>
                        <h3>Bog‘liqliklar xaritasi</h3>

                        <p class="muted">
                            Topshiriqlar o‘zaro bog‘liqligiga qarab guruhlangan.
                        </p>
                    </div>
                </header>

                <div class="chain-list" id="chain-list"></div>
            </article>

            <aside class="card chain-sidebar">
                <h3>Zanjirni qanday tushunish mumkin</h3>

                <ol class="chain-legend">
                    <li>
                        <strong>Asosiy</strong>

                        <span>
                            Mustaqil, yuqori darajadagi topshiriq.
                        </span>
                    </li>

                    <li>
                        <strong>Quyi topshiriq</strong>

                        <span>
                            Boshqa topshiriqqa bog‘liq topshiriq.
                        </span>
                    </li>

                    <li>
                        <strong>Bosing</strong>

                        <span>
                            Topshiriqni topshiriqlar sahifasida ochish uchun.
                        </span>
                    </li>
                </ol>
            </aside>
        </section>
    </section>

    <template id="chain-item-template">
        <article
            class="chain-item"
            data-task-id=""
            role="button"
            tabindex="0"
        >
            <div
                aria-hidden="true"
                class="chain-item__connector"
            ></div>

            <div>
                <span
                    class="chain-item__relation"
                    data-field="relation"
                ></span>

                <h3 data-field="title"></h3>

                <p data-field="number"></p>
            </div>

            <span
                class="status-badge"
                data-field="status"
            ></span>
        </article>
    </template>
</main>
@endsection

@section('overlays')
<div
    aria-atomic="true"
    aria-live="polite"
    class="toast-region"
    id="toast-region"
></div>
@endsection
