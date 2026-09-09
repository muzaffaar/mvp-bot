@php
    $headers = [
        'dashboard' => [
            'class' => 'dashboard-header',
            'title' => 'Boshqaruv paneli',
            'subtitle' => $dashboardSubtitle ?? 'Ishlar boshqarmasi',
        ],
        'tasks' => [
            'class' => 'tasks-app-header',
            'title' => 'Topshiriqlar',
            'subtitle' => null,
            'summary' => true,
        ],
        'people' => [
            'class' => '',
            'title' => 'Xodimlar registri',
            'subtitle' => null,
            'peopleSummary' => true,
        ],
        'staff-show' => [
            'class' => '',
            'title' => 'Xodim ma\'lumotlari',
            'subtitle' => 'Xodim va unga biriktirilgan topshiriqlar',
        ],
        'reports' => [
            'class' => '',
            'eyebrow' => 'IMV IB Support',
            'title' => 'Hisobotlar',
            'subtitle' => 'Asosiy ko\'rsatkichlar va topshiriqlar holati',
        ],
        'security' => [
            'class' => '',
            'title' => 'Xavfsizlik',
            'subtitle' => 'Parol, xavfsizlik jurnali va faol sessiyalarni boshqaring',
        ],
        'chain' => [
            'class' => '',
            'eyebrow' => 'IMV IB Support',
            'title' => 'Topshiriqlar zanjiri',
            'subtitle' => 'Topshiriqlar o\'rtasidagi bog\'lanishlarni ko\'ring',
        ],
    ];

    $header = $headers[$page] ?? null;
@endphp

@if ($header)
    <header class="app-header {{ $header['class'] }}">
        <div class="app-header__intro">
            @if (!empty($header['eyebrow']))
                <p class="eyebrow">{{ $header['eyebrow'] }}</p>
            @endif

            <h1 class="app-header__title">{{ $header['title'] }}</h1>

            @if (!empty($header['summary']))
                <p class="app-header__subtitle" data-task-summary>
                    Yuklanmoqda…
                </p>
            @elseif (!empty($header['peopleSummary']))
                <p class="app-header__subtitle" id="people-summary">
                    —
                </p>
            @else
                <p class="app-header__subtitle">
                    {{ $header['subtitle'] }}
                </p>
            @endif
        </div>

        <div class="app-header__actions">

            {{-- Qidiruv --}}
            <div class="global-search-wrap">
                <label class="global-search" for="global-search">

                    <span class="sr-only">Qidirish</span>

                    <input
                        autocomplete="off"
                        data-action="global-search"
                        id="global-search"
                        placeholder="Raqam, sarlavha yoki xodim..."
                        type="search"
                    />
                </label>

                <div class="global-search-results" hidden id="global-search-results" role="listbox"></div>
            </div>

            {{-- Mavzu --}}
            <button
                aria-label="Mavzuni almashtirish"
                class="icon-button theme-trigger"
                data-action="toggle-theme"
                type="button"
            >
                ◐
            </button>

            {{-- Bildirishnomalar --}}
            <button
                aria-controls="notification-card"
                aria-expanded="false"
                aria-label="Bildirishnomalar"
                class="icon-button notification-trigger"
                data-action="toggle-notifications"
                type="button"
            >
                ♧
                @if ($headerUnreadNotifications > 0)
                    <span class="notification-count">
                        {{ $headerUnreadNotifications }}
                    </span>
                @endif
            </button>

        </div>
    </header>
@endif
