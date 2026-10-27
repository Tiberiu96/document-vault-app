(() => {
    const form = document.querySelector('[data-resend-form]');
    if (!form) return;
    const button = form.querySelector('button[type="submit"]');
    const label = form.querySelector('[data-resend-label]');
    const until = Date.now() + Number(form.dataset.seconds || 0) * 1000;
    const tick = () => {
        const seconds = Math.max(0, Math.ceil((until - Date.now()) / 1000));
        button.disabled = seconds > 0;
        label.textContent = seconds >= 60
            ? form.dataset.minutes.replace('{minutes}', Math.floor(seconds / 60)).replace('{seconds}', seconds % 60)
            : (seconds ? form.dataset.countdown.replace('{seconds}', seconds) : form.dataset.label);
        if (seconds) setTimeout(tick, 1000);
    };
    tick();
    form.addEventListener('submit', () => { button.disabled = true; });
})();
