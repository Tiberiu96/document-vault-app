(() => {
    const form = document.querySelector('[data-ai-form]');
    form?.addEventListener('submit', () => { form.querySelector('button[type="submit"]').disabled = true; });
    const box = document.querySelector('[data-ai-result]');
    if (!box) return;
    let polls = 0;
    const poll = async () => {
        try {
            const response = await fetch(box.dataset.statusUrl, {headers: {'Accept': 'application/json'}, cache: 'no-store', credentials: 'same-origin'});
            if (!response.ok) throw new Error('status unavailable');
            const data = await response.json();
            if (data.status === 'ready') {
                box.querySelector('[data-ai-status]').hidden = true;
                const reply = box.querySelector('[data-ai-reply]');
                reply.textContent = data.reply;
                reply.hidden = false;
                return;
            }
            if (data.status === 'failed') throw new Error('probe failed');
            if (++polls < 120) { setTimeout(poll, 2500); return; }
        } catch (_) { /* Afiseaza numai mesajul localizat, fara date de provider. */ }
        box.querySelector('[data-ai-status]').textContent = box.dataset.failure;
    };
    poll();
})();
