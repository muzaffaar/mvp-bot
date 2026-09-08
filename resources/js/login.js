import QRCode from 'qrcode';

document.addEventListener('DOMContentLoaded', () => {
    /*
    |--------------------------------------------------------------------------
    | Elements
    |--------------------------------------------------------------------------
    */

    const qrLogin = document.getElementById('qr-login');
    const qrCode = document.getElementById('qr-code');
    const qrLoading = document.getElementById('qr-loading');
    const qrStatus = document.getElementById('qr-status');
    const qrCountdown = document.getElementById('qr-countdown');
    const qrRetryButton = document.getElementById('qr-retry-button');

    if (!qrLogin || !qrCode || !qrStatus) {
        return;
    }


    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    const createUrl = qrLogin.dataset.createUrl;
    const statusUrl = qrLogin.dataset.statusUrl;
    const authenticateUrl = qrLogin.dataset.authenticateUrl;


    /*
    |--------------------------------------------------------------------------
    | State
    |--------------------------------------------------------------------------
    */

    let pollingTimer = null;
    let countdownTimer = null;

    let currentSessionId = null;
    let expiresAt = null;

    let isAuthenticating = false;


    /*
    |--------------------------------------------------------------------------
    | CSRF
    |--------------------------------------------------------------------------
    */

    const csrfToken =
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content');


    /*
    |--------------------------------------------------------------------------
    | Generate QR
    |--------------------------------------------------------------------------
    */

    async function generateQr() {
        try {
            stopTimers();

            currentSessionId = null;
            expiresAt = null;
            isAuthenticating = false;

            qrRetryButton?.classList.add('hide');

            qrCode.innerHTML = '';

            if (qrLoading) {
                qrLoading.textContent =
                    'QR kod tayyorlanmoqda...';

                qrCode.appendChild(qrLoading);
            }

            qrStatus.textContent =
                'Yaratilmoqda';

            qrCountdown.textContent = '';


            /*
            |--------------------------------------------------------------------------
            | Browser fingerprint
            |--------------------------------------------------------------------------
            */

            const browserHash =
                await generateBrowserHash();


            /*
            |--------------------------------------------------------------------------
            | Create server-side QR session
            |--------------------------------------------------------------------------
            */

            const response =
                await fetch(createUrl, {
                    method: 'POST',

                    headers: {
                        'X-CSRF-TOKEN': csrfToken,
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                    },

                    body: JSON.stringify({
                        browser_hash: browserHash,
                    }),
                });


            const data =
                await response.json();


            if (!response.ok) {
                throw new Error(
                    data.message ||
                    'QR session yaratilmadi.'
                );
            }


            currentSessionId =
                data.session_id;

            expiresAt =
                data.expires_at;


            /*
            |--------------------------------------------------------------------------
            | Render QR
            |--------------------------------------------------------------------------
            */

            await renderQrCode(data.qr_url);


            /*
            |--------------------------------------------------------------------------
            | Waiting state
            |--------------------------------------------------------------------------
            */

            qrStatus.textContent =
                'Kutilmoqda';


            /*
            |--------------------------------------------------------------------------
            | Countdown
            |--------------------------------------------------------------------------
            */

            startCountdown();


            /*
            |--------------------------------------------------------------------------
            | Poll server
            |--------------------------------------------------------------------------
            */

            startPolling(currentSessionId);

        } catch (error) {

            console.error(
                'QR generation error:',
                error
            );

            qrStatus.textContent =
                error.message ||
                'QR kod yaratishda xatolik.';

            qrCountdown.textContent = '';

            showRetry();
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Render QR
    |--------------------------------------------------------------------------
    */

    async function renderQrCode(url) {
        qrCode.innerHTML = '';

        const canvas =
            document.createElement('canvas');


        /*
        |--------------------------------------------------------------------------
        | QR size
        |--------------------------------------------------------------------------
        |
        | The design container is 196x196.
        | Therefore don't use the old 280px size.
        |
        */

        await QRCode.toCanvas(
            canvas,
            url,
            {
                width: 178,
                margin: 1,
                errorCorrectionLevel: 'M',
            }
        );

        canvas.style.display = 'block';
        canvas.style.maxWidth = '100%';
        canvas.style.maxHeight = '100%';

        qrCode.appendChild(canvas);
    }


    /*
    |--------------------------------------------------------------------------
    | Polling
    |--------------------------------------------------------------------------
    */

    function startPolling(sessionId) {
        stopPolling();

        pollingTimer =
            setInterval(() => {
                checkStatus(sessionId);
            }, 1000);
    }


    function stopPolling() {
        if (pollingTimer) {
            clearInterval(pollingTimer);
            pollingTimer = null;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Check QR status
    |--------------------------------------------------------------------------
    */

    async function checkStatus(sessionId) {
        if (!sessionId || isAuthenticating) {
            return;
        }

        try {
            const response =
                await fetch(
                    `${statusUrl}/${encodeURIComponent(sessionId)}/status`,
                    {
                        method: 'GET',

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
            |--------------------------------------------------------------------------
            | Approved
            |--------------------------------------------------------------------------
            */

            if (data.status === 'approved') {

                stopPolling();
                stopCountdown();

                isAuthenticating = true;

                qrStatus.textContent =
                    'Login tasdiqlandi';

                qrCountdown.textContent =
                    'Kirilmoqda...';

                await authenticate(sessionId);

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Cancelled
            |--------------------------------------------------------------------------
            */

            if (data.status === 'cancelled') {

                stopPolling();
                stopCountdown();

                qrStatus.textContent =
                    'Login bekor qilindi.';

                qrCountdown.textContent = '';

                showRetry();

                return;
            }


            /*
            |--------------------------------------------------------------------------
            | Expired
            |--------------------------------------------------------------------------
            */

            if (
                data.status === 'expired' ||
                data.expired === true
            ) {

                stopPolling();
                stopCountdown();

                qrStatus.textContent =
                    'QR kod muddati tugadi.';

                qrCountdown.textContent = '';

                showRetry();

                return;
            }

        } catch (error) {

            console.error(
                'QR status error:',
                error
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Authenticate browser
    |--------------------------------------------------------------------------
    */

    async function authenticate(sessionId) {
        try {

            const response =
                await fetch(
                    `${authenticateUrl}/${encodeURIComponent(sessionId)}/authenticate`,
                    {
                        method: 'POST',

                        headers: {
                            'X-CSRF-TOKEN':
                                csrfToken,

                            'Accept':
                                'application/json',

                            'Content-Type':
                                'application/json',
                        },

                        body: JSON.stringify({}),
                    }
                );


            const data =
                await response.json();


            if (!response.ok) {
                throw new Error(
                    data.message ||
                    'Authentication failed.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Successful authentication
            |--------------------------------------------------------------------------
            */

            if (
                data.success &&
                data.redirect
            ) {

                window.location.href =
                    data.redirect;

                return;
            }


            throw new Error(
                'Authentication response noto‘g‘ri.'
            );

        } catch (error) {

            console.error(
                'Authentication error:',
                error
            );

            isAuthenticating = false;

            qrStatus.textContent =
                error.message ||
                'Login amalga oshmadi.';

            qrCountdown.textContent = '';

            showRetry();
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Countdown
    |--------------------------------------------------------------------------
    */

    function startCountdown() {
        stopCountdown();

        if (!expiresAt) {
            return;
        }


        function updateCountdown() {

            const expiration =
                new Date(expiresAt).getTime();

            const now =
                Date.now();

            const remaining =
                Math.max(
                    0,
                    expiration - now
                );

            const seconds =
                Math.ceil(
                    remaining / 1000
                );


            if (seconds <= 0) {

                stopCountdown();

                stopPolling();

                qrStatus.textContent =
                    'QR kod muddati tugadi.';

                qrCountdown.textContent = '';

                showRetry();

                return;
            }


            const minutes =
                Math.floor(seconds / 60);

            const remainingSeconds =
                seconds % 60;


            qrCountdown.textContent =
                `QR amal qilish muddati: ${
                    minutes
                }:${
                    String(remainingSeconds)
                        .padStart(2, '0')
                }`;
        }


        updateCountdown();


        countdownTimer =
            setInterval(
                updateCountdown,
                1000
            );
    }


    function stopCountdown() {
        if (countdownTimer) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Retry
    |--------------------------------------------------------------------------
    */

    function showRetry() {
        qrRetryButton?.classList.remove('hide');
    }


    /*
    |--------------------------------------------------------------------------
    | Stop everything
    |--------------------------------------------------------------------------
    */

    function stopTimers() {
        stopPolling();
        stopCountdown();
    }


    /*
    |--------------------------------------------------------------------------
    | Browser hash
    |--------------------------------------------------------------------------
    */

    async function generateBrowserHash() {

        const existing =
            sessionStorage.getItem(
                'qr_browser_hash'
            );


        if (existing) {
            return existing;
        }


        /*
        |--------------------------------------------------------------------------
        | Generate random browser identifier
        |--------------------------------------------------------------------------
        */

        const randomBytes =
            new Uint8Array(32);

        crypto.getRandomValues(
            randomBytes
        );


        const randomString =
            Array.from(randomBytes)
                .map(
                    byte =>
                        byte
                            .toString(16)
                            .padStart(2, '0')
                )
                .join('');


        /*
        |--------------------------------------------------------------------------
        | SHA-256
        |--------------------------------------------------------------------------
        */

        const encoded =
            new TextEncoder().encode(
                randomString
            );


        const hashBuffer =
            await crypto.subtle.digest(
                'SHA-256',
                encoded
            );


        const hashArray =
            Array.from(
                new Uint8Array(hashBuffer)
            );


        const hash =
            hashArray
                .map(
                    byte =>
                        byte
                            .toString(16)
                            .padStart(2, '0')
                )
                .join('');


        sessionStorage.setItem(
            'qr_browser_hash',
            hash
        );


        return hash;
    }


    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    */

    qrRetryButton?.addEventListener(
        'click',
        generateQr
    );


    /*
    |--------------------------------------------------------------------------
    | Start automatically
    |--------------------------------------------------------------------------
    |
    | Unlike the old page, there is no "QR orqali kirish" button.
    | QR is part of the design and therefore starts immediately.
    |
    */

    generateQr();
});
