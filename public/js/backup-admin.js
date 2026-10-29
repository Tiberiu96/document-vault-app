(() => {
    const pending = document.querySelector('[data-restore-pending]');
    if (pending) {
        const started = Date.now();
        const progress = pending.querySelector('[data-restore-progress]');
        const poll = async () => {
            if (Date.now() - started > 900000) {
                progress.textContent = 'Verifica restaurarea din terminal daca aplicatia nu a revenit. Nu reporni serviciile fara verificare.';
                return;
            }
            if (!document.hidden) {
                try {
                    const response = await fetch(pending.dataset.statusUrl, {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
                    if (response.redirected || response.status === 401) {
                        window.location.assign(pending.dataset.loginUrl);
                        return;
                    }
                    if (response.ok) {
                        const state = await response.json();
                        if (state.phase === 'succeeded' || state.phase === 'failed') {
                            window.location.reload();
                            return;
                        }
                    }
                } catch (_) { /* Mentenanta poate opri temporar FPM. */ }
                progress.textContent = 'Restaurare in curs. Pagina verifica revenirea aplicatiei; la succes va fi necesara autentificarea din nou.';
            }
            setTimeout(poll, 5000);
        };
        setTimeout(poll, 5000);
    }
    const dialog = document.getElementById('backup-restore-dialog');
    if (!dialog) return;
    document.querySelector('[data-open-restore]')?.addEventListener('click', () => dialog.showModal());
    dialog.querySelector('[data-close-restore]')?.addEventListener('click', () => dialog.close());
    dialog.querySelector('form')?.addEventListener('submit', (event) => {
        if (!event.currentTarget.checkValidity()) return;
        const button = event.currentTarget.querySelector('[type="submit"]');
        button.disabled = true;
        button.textContent = 'Se solicita restaurarea...';
    });
    dialog.addEventListener('close', () => {
        dialog.querySelectorAll('input').forEach(input => {
            if (input.type === 'checkbox') input.checked = false;
            else if (input.type !== 'hidden') input.value = '';
        });
    });
})();
