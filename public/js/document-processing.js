(() => {
    const box = document.querySelector('[data-document-processing]');
    if (!box || box.dataset.pending !== '1') return;
    const stage = box.querySelector('[data-processing-stage]');
    const message = box.querySelector('[data-processing-message]');
    const elapsed = box.querySelector('[data-processing-elapsed]');
    const active = status => ['queued', 'running'].includes(status);
    let elapsedSeconds = 0;
    let polls = 0;
    let finished = false;
    const clock = setInterval(() => {
        if (!elapsed || finished) return;
        elapsedSeconds++;
        const minutes = Math.floor(elapsedSeconds / 60);
        elapsed.textContent = `${minutes}:${String(elapsedSeconds % 60).padStart(2, '0')}`;
        if (elapsedSeconds >= 45 && message) message.textContent = box.dataset.slow;
    }, 1000);
    const poll = async () => {
        try {
            const response = await fetch(box.dataset.statusUrl, {headers: {'Accept': 'application/json'}, credentials: 'same-origin', cache: 'no-store'});
            if (!response.ok) throw new Error('status');
            const data = await response.json();
            box.querySelector('[data-ocr-state]').textContent = data.ocr_label;
            box.querySelector('[data-ai-state]').textContent = data.ai_label;
            if (!active(data.ocr_status) && !active(data.ai_status)) {
                finished = true;
                clearInterval(clock);
                if (data.ready_for_review && box.dataset.reviewUrl) location.replace(box.dataset.reviewUrl);
                else if (box.dataset.manualUrl) location.replace(box.dataset.manualUrl);
                else location.reload();
                return;
            }
            elapsedSeconds = Number.isFinite(data.elapsed_seconds) ? data.elapsed_seconds : elapsedSeconds;
            if (stage) stage.textContent = data.ocr_status === 'running' ? box.dataset.ocrStage : (data.ai_status === 'running' ? box.dataset.aiStage : box.dataset.waitStage);
            if (message && elapsedSeconds < 45) message.textContent = box.dataset.checking;
        } catch (_) {
            if (message) message.textContent = box.dataset.offline;
        }
        if (++polls < 300) setTimeout(poll, 2000);
        else {
            finished = true;
            clearInterval(clock);
            if (message) message.textContent = box.dataset.offline;
            box.querySelector('.processing-spinner')?.classList.add('is-paused');
        }
    };
    poll();
})();
