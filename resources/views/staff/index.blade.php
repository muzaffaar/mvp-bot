@extends('layouts.admin')

@php
$page = 'people';
$title = 'IMV IB Support — Xodimlar';
@endphp

@section('content')
<main class="app-content">
    <section aria-labelledby="people-heading" class="page-section people-page">
        <header class="people-page__header">
            <div>
                <h2 id="people-heading">Xodimlar</h2>
                <p class="muted">Xodimlar, taxalluslar, rollar va tizimdagi holatlarni boshqaring.</p>
            </div>
            <div class="people-page__actions">
                <form action="{{ route('staff.index') }}" id="people-search-form" method="GET">
                    <label class="field field--inline">
                        <span>Qidirish</span>
                        <input data-action="search-people" id="people-search" name="search"
                            placeholder="Ism, username yoki Telegram ID" type="search" value="{{ $search ?? '' }}" />
                    </label>
                </form>
            </div>
        </header>
        <section aria-label="Xodimlar jadvali" class="people-table-card card">
            <div class="people-table-wrap">
                <table class="people-table">
                    <thead>
                        <tr>
                            <th>Xodim</th>
                            <th>Telegram ID</th>
                            <th>Taxalluslar</th>
                            <th>Guruhlar va rollar</th>
                            <th>Holat</th>
                            <th><span class="sr-only">Amallar</span></th>
                        </tr>
                    </thead>
                    <tbody id="people-list">

                        @forelse ($staff as $person)

                        @php
                        $status = strtoupper($person->status ?? '');

                        $isDeleted = $person->trashed();

                        $statusKey = $isDeleted ? 'deleted' : strtolower($person->status ?? '');

                        $statusLabel = match(true) {
                        $isDeleted => "O'chirilgan",
                        $status === 'ACTIVE' => 'Faol',
                        $status === 'BLOCKED' => 'Bloklangan',
                        $status === 'INACTIVE' => 'Tasdiq kutilmoqda',
                        default => 'Nomaʼlum',
                        };

                        $initials = collect(
                        preg_split('/\s+/', trim($person->full_name ?? ''))
                        )
                        ->filter()
                        ->take(2)
                        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
                        ->implode('');

                        $roles = $person->roles
                        ->pluck('name')
                        ->values();
                        @endphp

                        <tr class="person-row {{ $isDeleted ? 'person-row--deleted' : '' }}" data-person-id="{{ $person->id }}"
                            data-person-name="{{ $person->full_name }}" data-person-username="{{ $person->username }}"
                            data-person-telegram-id="{{ $person->telegram_chat_id }}"
                            data-person-status="{{ $statusKey }}" data-person-post="{{ $person->lavozim }}"
                            data-person-created-at="{{ optional($person->created_at)->toISOString() }}"
                            data-person-roles='@json($roles)' data-person-group-id="{{ $person->group_chat_id }}"
                            data-person-group-name="{{ $person->group_name }}">

                            {{-- Xodim --}}
                            <td>

                                <a class="person-row__profile person-row__profile--link" href="{{ route('staff.show', $person) }}">

                                    <span class="person-avatar">
                                        {{ $initials ?: '?' }}
                                    </span>

                                    <span class="person-row__identity">

                                        <strong>
                                            {{ $person->full_name }}
                                        </strong>

                                        <small>

                                            @if ($person->username)
                                            {{ '@' . ltrim($person->username, '@') }}
                                            @else
                                            Username yo‘q
                                            @endif

                                        </small>

                                    </span>

                                </a>

                            </td>


                            {{-- Telegram ID --}}
                            <td>

                                <code>
                    {{ $person->telegram_chat_id ?? '—' }}
                </code>

                            </td>


                            {{-- Taxalluslar --}}
                            <td>

                                <div class="person-aliases">

                                    @if ($person->username)

                                    <span class="alias-chip">
                                        {{ '@' . ltrim($person->username, '@') }}
                                    </span>

                                    @else

                                    <span class="muted">
                                        —
                                    </span>

                                    @endif

                                </div>

                            </td>


                            {{-- Guruhlar va rollar --}}
                            <td>

                                <div class="person-groups">

                                    @if ($person->group_name)

                                    <span class="group-chip">
                                        {{ $person->group_name }}
                                    </span>

                                    @endif


                                    @forelse ($roles as $role)

                                    <span class="group-chip">
                                        {{ $role }}
                                    </span>

                                    @empty

                                    @if (!$person->group_name)
                                    <span class="muted">
                                        —
                                    </span>
                                    @endif

                                    @endforelse

                                </div>

                            </td>


                            {{-- Holat --}}
                            <td>

                                <span class="person-status" data-status="{{ $statusKey }}">

                                    {{ $statusLabel }}

                                </span>

                            </td>


                            {{-- Actions --}}
                            <td>

                                @unless ($isDeleted)
                                <button aria-label="Xodimni tahrirlash" class="table-icon-button"
                                    data-action="edit-person" data-person-id="{{ $person->id }}"
                                    data-full-name="{{ $person->full_name }}" data-username="{{ $person->username }}"
                                    data-login="{{ $person->login }}" data-lavozim="{{ $person->lavozim }}"
                                    data-status="{{ $person->status }}"
                                    data-telegram-chat-id="{{ $person->telegram_chat_id }}"
                                    data-group-chat-id="{{ $person->group_chat_id }}"
                                    data-group-name="{{ $person->group_name }}"
                                    data-role="{{ $person->roles->first()?->name }}"
                                    data-update-url="{{ route('staff.update', $person) }}" type="button">
                                    ✎
                                </button>
                                @endunless

                            </td>

                        </tr>

                        @empty

                        <tr>

                            <td colspan="6">

                                <p class="empty-state">
                                    Xodim topilmadi.
                                </p>

                            </td>

                        </tr>

                        @endforelse

                    </tbody>
                </table>
            </div>
            <div class="people-table-footer">
                <p class="muted" id="people-pagination-summary">
                    {{ $staff->total() }} ta xodimdan {{ $staff->firstItem() ?? 0 }}–{{ $staff->lastItem() ?? 0 }}
                </p>
                @if ($staff->hasPages())
                <nav aria-label="Xodimlar sahifalari" class="pagination" id="people-pagination">
                    @if (!$staff->onFirstPage())
                    <a class="pagination-button" href="{{ $staff->previousPageUrl() }}">‹</a>
                    @endif
                    @foreach ($staff->getUrlRange(1, $staff->lastPage()) as $page => $url)
                    <a class="pagination-page {{ $page === $staff->currentPage() ? 'is-active' : '' }}"
                        href="{{ $url }}">{{ $page }}</a>
                    @endforeach
                    @if ($staff->hasMorePages())
                    <a class="pagination-button" href="{{ $staff->nextPageUrl() }}">›</a>
                    @endif
                </nav>
                @else
                <nav aria-label="Xodimlar sahifalari" class="pagination" id="people-pagination"></nav>
                @endif
            </div>
        </section>
        <p class="empty-state" hidden="" id="people-empty">Xodim topilmadi.</p>
    </section>
</main>
@endsection

@section('overlays')
<template id="person-row-template">
    <tr class="person-row" data-person-id="">
        <td>
            <button class="person-row__profile" data-action="open-person-profile" type="button">
                <span class="person-avatar" data-field="initials"></span>
                <span class="person-row__identity"><strong data-field="name"></strong><small
                        data-field="meta"></small></span>
            </button>
        </td>
        <td><code data-field="telegram-id"></code></td>
        <td>
            <div class="person-aliases" data-field="aliases"></div>
        </td>
        <td>
            <div class="person-groups" data-field="groups"></div>
        </td>
        <td><span class="person-status" data-field="status"></span></td>
        <td><button aria-label="Xodimni tahrirlash" class="table-icon-button" data-action="edit-person"
                type="button">✎</button></td>
    </tr>
</template>
<template id="person-alias-template"><span class="alias-chip" data-field="alias"></span></template>
<template id="person-group-template"><span class="group-chip" data-field="group"></span></template>
<template id="pagination-button-template"><button class="pagination__button" data-action="change-people-page"
        data-page="" type="button"></button></template>

@section('overlays')
{{-- STAFF EDIT MODAL --}}
<div class="modal-backdrop" hidden id="person-edit-modal">

    <section aria-labelledby="person-edit-modal-title" aria-modal="true" class="person-profile-modal card"
        role="dialog">

        <header class="person-profile-modal__header">

            <h2 id="person-edit-modal-title">
                Xodimni tahrirlash
            </h2>

            <button aria-label="Yopish" class="modal-close" data-action="close-person-edit-modal" type="button">
                ×
            </button>

        </header>


        <form id="person-edit-form" method="POST" action="">

            @csrf

            @method('PUT')

            <div class="person-profile-modal__body">

                {{-- Hidden staff ID --}}
                <input id="edit-person-id" name="person_id" type="hidden">


                <div class="person-profile-columns">

                    {{-- LEFT COLUMN --}}
                    <section class="person-profile-section">

                        <h3>Asosiy ma'lumotlar</h3>


                        {{-- Full Name --}}
                        <label class="field">

                            <span>To'liq ism</span>

                            <input id="edit-full-name" name="full_name" required type="text">

                        </label>


                        {{-- Login --}}
                        <label class="field">

                            <span>Login</span>

                            <input id="edit-login" name="login" type="text">

                        </label>


                        {{-- Position --}}
                        <label class="field">

                            <span>Lavozim</span>

                            <input id="edit-lavozim" name="lavozim" type="text">

                        </label>

                    </section>


                    {{-- RIGHT COLUMN --}}
                    <section class="person-profile-section">

                        <h3>Tizim ma'lumotlari</h3>

                        {{-- Telegram Chat ID --}}
                        <label class="field">

                            <span>Telegram ID</span>

                            <input id="edit-telegram-chat-id" name="telegram_chat_id" type="text">

                        </label>

                        <label class="field">

                            <span>Holat</span>

                            <select id="edit-status" name="status" required>
                                <option value="active">Faol</option>
                                <option value="inactive">Nofaol</option>
                                <option value="blocked">Bloklangan</option>
                            </select>

                        </label>


                        {{-- Role --}}
                        <label class="field">

                            <span>Rol</span>

                            <select id="edit-role" name="role" required>

                                @foreach($availableRoles as $role)
                                    <option value="{{ $role->name }}">
                                        {{ $role->name }}
                                    </option>
                                @endforeach

                            </select>

                        </label>

                        <div class="person-profile-columns">

                        <label class="field">

                            <span>Guruh nomi</span>

                            <input id="edit-group-name" name="group_name" type="text">

                        </label>


                        <label class="field">

                            <span>Guruh Chat ID</span>

                            <input id="edit-group-chat-id" name="group_chat_id" type="text">

                        </label>

                    </div>

                    </section>

                </div>




                {{-- Password --}}
                <section class="person-profile-section">


                    <p class="muted">
                        Parolni o'zgartirmoqchi bo'lmasangiz, bo'sh qoldiring.
                    </p>


                    <div class="person-profile-columns">

                        <label class="field">

                            <span>Yangi parol</span>

                            <input autocomplete="new-password" id="edit-password" name="password" type="password">

                        </label>


                        <label class="field">

                            <span>Parolni tasdiqlash</span>

                            <input autocomplete="new-password" id="edit-password-confirmation"
                                name="password_confirmation" type="password">

                        </label>

                    </div>

                </section>

            </div>


            <footer class="person-profile-modal__footer">

                <button class="btn btn--ghost" data-action="close-person-edit-modal" type="button">
                    Bekor qilish
                </button>


                <button class="btn" type="submit">
                    Saqlash
                </button>

            </footer>

        </form>

    </section>

</div>
@endsection

<template id="person-profile-group-template">
    <div class="person-profile-group-row">
        <div class="person-profile-group-name"><span class="status-dot status-dot--online"></span>
            <div><strong data-field="group-name"></strong><small data-field="group-date"></small></div>
        </div>
        <div class="person-profile-role-list" data-field="profile-roles"></div>
        <strong class="person-profile-group-count" data-field="group-task-count">0</strong>
        <div class="person-profile-group-timeliness">
            <div class="person-profile-timeliness-bar"><span data-field="group-timeliness-bar"></span></div><b
                data-field="group-timeliness-value">0%</b>
        </div>
    </div>
</template>
<template id="person-profile-role-template"><span class="person-profile-role"
        data-field="profile-role"></span></template>
<template id="person-profile-alias-template"><span class="alias-chip" data-field="profile-alias"></span></template>
<div aria-atomic="true" aria-live="polite" class="toast-region" id="toast-region"></div>
@endsection
