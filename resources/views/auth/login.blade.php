<!DOCTYPE html>

<html lang="uz">

<head>
    <meta charset="UTF-8">


    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>IMV IB Support — Tizimga kirish</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">

    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">


</head>

<body data-page="login" class="login-body">


    <button type="button" class="login-theme-toggle" data-action="toggle-theme" aria-label="Mavzuni almashtirish">
        ◐
        <span>Mavzu</span>
    </button>


    <main class="login-layout">


        {{-- LEFT BRAND PANEL --}}
        <section class="login-brand-panel" aria-labelledby="login-brand-heading">

            <header class="login-brand-panel__brand">

                <span class="login-brand-panel__mark">
                    I
                </span>

                <div>

                    <strong>
                        IMV IB Support
                    </strong>

                    <span>
                        Topshiriqlar boshqaruvi
                    </span>

                </div>

            </header>


            <div class="login-brand-panel__content">

                <h1 id="login-brand-heading">

                    Telegramdagi<br>
                    topshiriq —<br>
                    nazoratdagi ish

                </h1>


                <p>

                    Guruhdagi xabar avtomatik topshiriqqa
                    aylanadi, ijrochiga biriktiriladi
                    va muddatigacha kuzatiladi.

                </p>

            </div>


            <footer class="login-brand-panel__footer">

                Ichki xizmat tizimi ·
                kirish faqat ro‘yxatdan o‘tgan
                xodimlar uchun

            </footer>

        </section>



        {{-- LOGIN CONTENT --}}
        <section class="login-content" aria-labelledby="login-heading">

            <div class="login-card-wrap">


                <header class="login-heading">

                    <h2 id="login-heading">
                        Tizimga kirish
                    </h2>

                    <p>
                        Telegram akkauntingiz orqali —
                        parolsiz va tezroq
                    </p>

                </header>



                {{-- TELEGRAM QR LOGIN --}}
                <section class="login-telegram-card" aria-label="Telegram QR orqali kirish" id="telegram-qr-login">


                    <div class="login-qr-art" id="login-qr-art" aria-label="Telegram QR kodi">

                        <span id="login-qr-placeholder"></span>

                    </div>



                    <p class="login-telegram-card__title">

                        QR kodni skanerlang

                    </p>



                    <p class="login-telegram-card__text">

                        <strong>
                            {{ config('services.telegram.bot_username') }}
                        </strong>

                        da

                        <strong>
                            "Tasdiqlash"
                        </strong>

                        tugmasini bosing

                    </p>



                    <span class="login-telegram-card__status" id="qr-login-status">

                        ● QR kod yaratilmoqda...

                    </span>


                </section>



                <div class="login-divider">

                    <span>
                        yoki zahira usul
                    </span>

                </div>



                {{-- PASSWORD LOGIN --}}
                <form id="login-form" class="login-form" method="POST" action="{{ route('login.post') }}">
    @csrf

    <label class="login-field" for="login-username">
        <span>Login</span>

        <input
            id="login-username"
            name="login"
            type="text"
            value="{{ old('login') }}"
            autocomplete="username"
            required
        >
    </label>

    @error('login')
        <p class="login-field-error">
            {{ $message }}
        </p>
    @enderror

    <label class="login-field" for="login-password">
        <span>Parol</span>

        <input
            id="login-password"
            name="password"
            type="password"
            autocomplete="current-password"
            required
        >
    </label>

    @error('password')
        <p class="login-field-error">
            {{ $message }}
        </p>
    @enderror

    {{-- <p class="login-security-note">
        ⌾ Boshliq roli uchun ikki bosqichli tasdiq majburiy —
        keyingi qadamda Telegramga kod yuboriladi.
    </p> --}}

    <button type="submit" class="login-submit">
        Davom etish
    </button>
</form>

            </div>

        </section>

    </main>



    <div id="toast-region" class="toast-region" aria-live="polite" aria-atomic="true">
    </div>



    {{-- Laravel route configuration for JavaScript --}}
    <script>

        window.QR_LOGIN_CONFIG = {

            createUrl: @json(
                route('login.qr.create')
            ),

            statusUrlTemplate: @json(
                url('/login/qr/__SESSION__/status')
            ),

            authenticateUrlTemplate: @json(
                url('/login/qr/__SESSION__/authenticate')
            ),

            csrfToken: @json(
                csrf_token()
            ),

        };

    </script>



    <script src="{{ asset('assets/js/app.js') }}"></script>


    {{-- QR CODE LOGIN --}}
    <script>

        document.addEventListener(
            'DOMContentLoaded',
            () => {

                const config =
                    window.QR_LOGIN_CONFIG;


                const qrContainer =
                    document.getElementById(
                        'login-qr-art'
                    );


                const statusElement =
                    document.getElementById(
                        'qr-login-status'
                    );


                let sessionId = null;

                let expiresAt = null;

                let statusInterval = null;

                let timerInterval = null;


                /*
                 |--------------------------------------------------------------------------
                 | Browser Hash
                 |--------------------------------------------------------------------------
                 |
                 | Your backend requires exactly:
                 |
                 | SHA-256
                 | 64 hexadecimal characters
                 |
                 */

                const generateBrowserHash =
                    async () => {

                        let browserId =
                            localStorage.getItem(
                                'imv_browser_id'
                            );


                        if (!browserId) {

                            browserId =
                                crypto.randomUUID();

                            localStorage.setItem(
                                'imv_browser_id',
                                browserId
                            );

                        }


                        const rawData =
                            new TextEncoder()
                                .encode(
                                    browserId
                                );


                        const hashBuffer =
                            await crypto.subtle.digest(
                                'SHA-256',
                                rawData
                            );


                        const hashArray =
                            Array.from(
                                new Uint8Array(
                                    hashBuffer
                                )
                            );


                        return hashArray
                            .map(
                                byte =>
                                    byte
                                        .toString(16)
                                        .padStart(
                                            2,
                                            '0'
                                        )
                            )
                            .join('');

                    };



                /*
                 |--------------------------------------------------------------------------
                 | Create QR Image
                 |--------------------------------------------------------------------------
                 */

                const renderQrCode =
                    (qrUrl) => {

                        if (!qrContainer) {
                            return;
                        }


                        qrContainer.innerHTML = '';


                        const image =
                            document.createElement(
                                'img'
                            );


                        /*
                         * QR generation using
                         * public QR image endpoint.
                         *
                         * You can replace this with
                         * an installed local QR library
                         * later if desired.
                         */

                        image.src =
                            'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data='
                            +
                            encodeURIComponent(
                                qrUrl
                            );


                        image.alt =
                            'Telegram QR login';


                        image.className =
                            'login-qr-image';


                        qrContainer.appendChild(
                            image
                        );

                    };



                /*
                 |--------------------------------------------------------------------------
                 | Create QR Login Session
                 |--------------------------------------------------------------------------
                 */

                const createQrSession =
                    async () => {

                        try {

                            const browserHash =
                                await generateBrowserHash();


                            const response =
                                await fetch(
                                    config.createUrl,
                                    {

                                        method: 'POST',

                                        headers: {

                                            'Content-Type':
                                                'application/json',

                                            'Accept':
                                                'application/json',

                                            'X-CSRF-TOKEN':
                                                config.csrfToken,

                                        },


                                        body:
                                            JSON.stringify(
                                                {

                                                    browser_hash:
                                                        browserHash,

                                                }
                                            ),

                                    }
                                );


                            const data =
                                await response.json();


                            if (!response.ok) {

                                throw new Error(
                                    data.message
                                    ||
                                    'QR session could not be created.'
                                );

                            }


                            sessionId =
                                data.session_id;


                            expiresAt =
                                data.expires_at;


                            renderQrCode(
                                data.qr_url
                            );


                            updateStatusText();

                            startPolling();

                        } catch (error) {

                            console.error(
                                error
                            );


                            if (statusElement) {

                                statusElement.textContent =
                                    '● QR kodni yaratishda xatolik yuz berdi';

                            }

                        }

                    };



                /*
                 |--------------------------------------------------------------------------
                 | QR Countdown
                 |--------------------------------------------------------------------------
                 */

                const updateStatusText =
                    () => {

                        if (
                            !expiresAt
                            ||
                            !statusElement
                        ) {
                            return;
                        }


                        const expiration =
                            new Date(
                                expiresAt
                            ).getTime();


                        const now =
                            Date.now();


                        const difference =
                            Math.max(
                                0,
                                expiration - now
                            );


                        const minutes =
                            Math.floor(
                                difference / 60000
                            );


                        const seconds =
                            Math.floor(
                                (
                                    difference
                                    % 60000
                                )
                                / 1000
                            );


                        statusElement.textContent =
                            '● Kutilmoqda · '
                            +
                            String(
                                minutes
                            ).padStart(
                                2,
                                '0'
                            )
                            +
                            ':'
                            +
                            String(
                                seconds
                            ).padStart(
                                2,
                                '0'
                            );


                        if (
                            difference <= 0
                        ) {

                            clearInterval(
                                timerInterval
                            );


                            clearInterval(
                                statusInterval
                            );


                            statusElement.textContent =
                                '● QR sessiya muddati tugadi';

                        }

                    };



                /*
                 |--------------------------------------------------------------------------
                 | Poll QR Status
                 |--------------------------------------------------------------------------
                 */

                const checkQrStatus =
                    async () => {

                        if (!sessionId) {
                            return;
                        }


                        try {

                            const statusUrl =
                                config
                                    .statusUrlTemplate
                                    .replace(
                                        '__SESSION__',
                                        sessionId
                                    );


                            const response =
                                await fetch(
                                    statusUrl,
                                    {

                                        headers: {

                                            'Accept':
                                                'application/json',

                                        },

                                    }
                                );


                            if (!response.ok) {
                                return;
                            }


                            const data =
                                await response.json();


                            /*
                             * Telegram approved login
                             */

                            if (
                                data.approved === true
                            ) {

                                clearInterval(
                                    statusInterval
                                );


                                clearInterval(
                                    timerInterval
                                );


                                if (
                                    statusElement
                                ) {

                                    statusElement.textContent =
                                        '● Tasdiqlandi · tizimga kirilmoqda...';

                                }


                                authenticateQrSession();

                                return;

                            }


                            /*
                             * Session expired
                             */

                            if (
                                data.expired === true
                                ||
                                data.status === 'expired'
                            ) {

                                clearInterval(
                                    statusInterval
                                );


                                clearInterval(
                                    timerInterval
                                );


                                if (
                                    statusElement
                                ) {

                                    statusElement.textContent =
                                        '● QR sessiya muddati tugadi';

                                }

                            }

                        } catch (error) {

                            console.error(
                                'QR status check failed:',
                                error
                            );

                        }

                    };



                /*
                 |--------------------------------------------------------------------------
                 | Finalize Authentication
                 |--------------------------------------------------------------------------
                 */

                const authenticateQrSession =
                    async () => {

                        if (!sessionId) {
                            return;
                        }


                        try {

                            const authenticateUrl =
                                config
                                    .authenticateUrlTemplate
                                    .replace(
                                        '__SESSION__',
                                        sessionId
                                    );


                            const response =
                                await fetch(
                                    authenticateUrl,
                                    {

                                        method:
                                            'POST',


                                        headers: {

                                            'Accept':
                                                'application/json',

                                            'X-CSRF-TOKEN':
                                                config.csrfToken,

                                        },

                                    }
                                );


                            const data =
                                await response.json();


                            if (!response.ok) {

                                throw new Error(
                                    data.message
                                    ||
                                    'Authentication failed.'
                                );

                            }


                            if (
                                data.success === true
                            ) {

                                window.location.href =
                                    data.redirect;

                            }

                        } catch (error) {

                            console.error(
                                error
                            );


                            if (
                                statusElement
                            ) {

                                statusElement.textContent =
                                    '● Tizimga kirishda xatolik yuz berdi';

                            }

                        }

                    };



                /*
                 |--------------------------------------------------------------------------
                 | Start QR Login
                 |--------------------------------------------------------------------------
                 */

                createQrSession();


                timerInterval =
                    setInterval(
                        updateStatusText,
                        1000
                    );


                const startPolling =
                    () => {

                        checkQrStatus();


                        statusInterval =
                            setInterval(
                                checkQrStatus,
                                2000
                            );

                    };

            }
        );

    </script>

</body>

</html>
