<!DOCTYPE html>
<html lang="uz">
<head>
    <meta charset="UTF-8">
    <script>
        (function () {
            try {
                var theme = window.localStorage.getItem("imv-theme");
                if (!theme) {
                    theme = window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
                }
                document.documentElement.dataset.theme = theme;
            } catch (_) {}
        })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta content="{{ csrf_token() }}" name="csrf-token">
    <title>{{ $title ?? 'IMV IB Support' }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
    @stack('styles')
</head>
<body data-page="{{ $page }}">
    <div class="app-layout">
        @include('components.sidebar', ['page' => $page])
        <div class="app-main">
            @include('components.topbar', ['page' => $page])
            @include('components.notifications')
            @yield('content')
        </div>
    </div>
    @yield('overlays')
    <script>
        window.INITIAL_DB = @json($data ?? []);
    </script>
    @stack('scripts')
    <script src="{{ asset('assets/js/app.js') }}"></script>
    @stack('scripts-after-app')
</body>
</html>
