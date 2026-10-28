(() => {
    'use strict';
    const fields = [...document.querySelectorAll('[data-turnstile-field]')];
    const setReady = (field, ready, failed = false) => {
        field.dataset.ready = ready ? 'true' : 'false';
        const status = field.querySelector('[data-turnstile-status]');
        status.textContent = ready ? '' : (failed ? field.dataset.failed : field.dataset.waiting);
        status.hidden = ready;
        field.closest('form')?.querySelectorAll('[type="submit"]').forEach(button => {
            button.disabled = !ready;
        });
    };
    fields.forEach(field => {
        setReady(field, false);
        field.closest('form')?.addEventListener('submit', event => {
            if (field.dataset.ready !== 'true') { event.preventDefault(); return; }
            field.dataset.ready = 'false';
            field.closest('form').querySelectorAll('[type="submit"]').forEach(button => { button.disabled = true; });
        });
    });
    document.querySelector('script[src^="https://challenges.cloudflare.com/turnstile/"]')?.addEventListener('error', () => {
        fields.forEach(field => setReady(field, false, true));
    });
    setTimeout(() => {
        if (!window.turnstile) fields.forEach(field => setReady(field, false, true));
    }, 15000);
    window.addEventListener('pageshow', event => { if (event.persisted) window.location.reload(); });
    window.vaultyTurnstileReady = () => {
        fields.forEach(field => {
            if (field.dataset.rendered === 'true') return;
            field.dataset.rendered = 'true';
            try {
                window.turnstile.render(field.querySelector('[data-turnstile-container]'), {
                    sitekey: field.dataset.siteKey,
                    action: field.dataset.action,
                    language: field.dataset.language,
                    theme: 'light',
                    size: field.clientWidth < 300 ? 'compact' : 'flexible',
                    callback: () => setReady(field, true),
                    'expired-callback': () => setReady(field, false),
                    'timeout-callback': () => setReady(field, false, true),
                    'error-callback': () => { setReady(field, false, true); return true; }
                });
            } catch {
                setReady(field, false, true);
            }
        });
    };
})();
