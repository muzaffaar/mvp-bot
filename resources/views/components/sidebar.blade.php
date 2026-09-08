<aside aria-label="Asosiy navigatsiya" class="app-sidebar">

    {{-- Brand --}}
    <a class="app-brand" href="{{ route('dashboard') }}">

        <span class="app-brand__mark">
            I
        </span>

        <span>

            <strong>
                IMV IB Support
            </strong>

            <small>
                Topshiriqlar boshqaruvi
            </small>

        </span>

    </a>


    {{-- Current User Role --}}
    <p class="app-sidebar__role">

        Sizning rolingiz:

        <strong>
            {{ strtoupper($sidebarData['user']['role'] ?? '—') }}
        </strong>

    </p>


    {{-- Navigation --}}
    <nav aria-label="Ish maydoni" class="app-nav">

        {{-- Dashboard --}}
        <a
            class="app-nav__link {{ $page === 'dashboard' ? 'is-active' : '' }}"
            href="{{ route('dashboard') }}"
        >

            <span aria-hidden="true">
                ▦
            </span>

            <span>
                Boshqaruv paneli
            </span>

        </a>


        {{-- Tasks --}}
        <a
            class="app-nav__link {{ $page === 'tasks' ? 'is-active' : '' }}"
            href="{{ route('tasks.index') }}"
        >

            <span aria-hidden="true">
                ⚐
            </span>

            <span>
                Topshiriqlar
            </span>

            <b class="nav-count">

                {{ $sidebarData['tasksCount'] ?? 0 }}

            </b>

        </a>


        {{-- Chain --}}
        <a
            class="app-nav__link {{ $page === 'chain' ? 'is-active' : '' }}"
            href="{{ route('chain.index') }}"
        >

            <span aria-hidden="true">
                ↔
            </span>

            <span>
                Zanjir
            </span>

        </a>


        {{-- Staff --}}
        <a
            class="app-nav__link {{ $page === 'people' ? 'is-active' : '' }}"
            href="{{ route('staff.index') }}"
        >

            <span aria-hidden="true">
                ♧
            </span>

            <span>
                Xodimlar
            </span>

        </a>


        {{-- Reports --}}
        <a
            class="app-nav__link {{ $page === 'reports' ? 'is-active' : '' }}"
            href="{{ route('reports.index') }}"
        >

            <span aria-hidden="true">
                ▤
            </span>

            <span>
                Hisobotlar
            </span>

        </a>


        {{-- Security --}}
        <a
            class="app-nav__link {{ $page === 'security' ? 'is-active' : '' }}"
            href="{{ route('security.index') }}"
        >

            <span aria-hidden="true">
                ♢
            </span>

            <span>
                Xavfsizlik
            </span>

        </a>

    </nav>


    {{-- Current User --}}
    <section
        aria-label="Joriy foydalanuvchi"
        class="app-sidebar__footer dashboard-profile"
    >

        {{-- Full name --}}
        <button
            class="dashboard-profile__switch"
            type="button"
        >

            {{ $sidebarData['user']['full_name'] ?? 'Foydalanuvchi' }}

        </button>


        {{-- Telegram Chat ID --}}
        <span class="dashboard-profile__role">

            Telegram ID:

            {{ $sidebarData['user']['telegram_chat_id'] ?? '—' }}

        </span>


        <div class="sidebar-utility-actions">

            {{-- Theme --}}
            <button
                aria-label="Qorong‘i rejimni yoqish"
                class="sidebar-theme-toggle"
                data-action="toggle-theme"
                type="button"
            >

                ◐

                <span>
                    Mavzu
                </span>

            </button>


            {{-- Logout --}}
            <form
                method="POST"
                action="{{ route('logout') }}"
            >

                @csrf

                <button
                    aria-label="Chiqish"
                    class="sidebar-logout"
                    type="submit"
                >

                    ↪

                </button>

            </form>

        </div>

    </section>

</aside>
