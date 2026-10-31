(() => {
    const root = document.querySelector('[data-assistant]');
    if (!root) return;

    const panel = root.querySelector('.assistant-panel');
    const openButton = root.querySelector('[data-assistant-open]');
    const closeButton = root.querySelector('[data-assistant-close]');
    const form = root.querySelector('[data-assistant-form]');
    const input = form.querySelector('textarea');
    const sendButton = form.querySelector('[type="submit"]');
    const stopButton = root.querySelector('[data-assistant-stop]');
    const messagesNode = root.querySelector('[data-assistant-messages]');
    const scrollNode = root.querySelector('[data-assistant-scroll]');
    const suggestions = root.querySelector('[data-assistant-suggestions]');
    const status = root.querySelector('[data-assistant-status]');
    const conversation = [];
    let controller = null;
    let retryText = '';

    const updateViewport = () => {
        const viewport = window.visualViewport;
        panel.style.setProperty('--assistant-viewport-height', `${viewport?.height ?? window.innerHeight}px`);
        panel.style.setProperty('--assistant-viewport-top', `${viewport?.offsetTop ?? 0}px`);
    };
    const setOpen = (open) => {
        updateViewport();
        if (open && !panel.open) panel.showModal();
        if (!open && panel.open) panel.close();
        openButton.setAttribute('aria-expanded', String(open));
        document.body.classList.toggle('assistant-open', open);
        if (open && window.matchMedia('(min-width:801px)').matches) input.focus({ preventScroll: true });
    };
    panel.addEventListener('close', () => {
        openButton.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('assistant-open');
    });
    panel.addEventListener('click', (event) => {
        const bounds = panel.getBoundingClientRect();
        if (event.target === panel && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) setOpen(false);
    });
    window.visualViewport?.addEventListener('resize', updateViewport);
    window.visualViewport?.addEventListener('scroll', updateViewport);
    window.addEventListener('resize', updateViewport);
    openButton.addEventListener('click', () => setOpen(true));
    closeButton.addEventListener('click', () => setOpen(false));

    const addMessage = (role, text, pending = false) => {
        root.querySelector('[data-assistant-welcome]')?.remove();
        const item = document.createElement('div');
        item.className = `assistant-message assistant-message-${role}`;
        item.textContent = text;
        if (pending) item.dataset.pending = 'true';
        messagesNode.append(item);
        scrollNode.scrollTop = scrollNode.scrollHeight;
        return item;
    };
    const setStatus = (text) => {
        status.textContent = text;
        status.hidden = !text;
    };
    const setBusy = (busy) => {
        input.disabled = busy;
        sendButton.hidden = busy;
        stopButton.hidden = !busy;
        suggestions.hidden = busy || conversation.length > 0;
    };
    const statusForTool = (name) => ({
        list_my_documents: root.dataset.listStatus,
        get_document: root.dataset.documentStatus,
        create_reminder: root.dataset.reminderStatus,
    })[name] || root.dataset.working;
    const parseResponse = async (response, assistantNode) => {
        if (!response.ok) {
            let body = {};
            try { body = await response.json(); } catch (_) {}
            throw new Error(body.message || root.dataset.failed);
        }
        if (!response.body) throw new Error(root.dataset.failed);
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let started = false;
        while (true) {
            const { value, done } = await reader.read();
            buffer += decoder.decode(value || new Uint8Array(), { stream: !done });
            const lines = buffer.split('\n');
            buffer = done ? '' : lines.pop();
            for (const line of lines) {
                if (!line.startsWith('data:')) continue;
                let event;
                try { event = JSON.parse(line.slice(5).trim()); } catch (_) { continue; }
                if (event.type === 'tool_call') setStatus(statusForTool(event.name));
                if (event.type === 'text') {
                    if (!started) { assistantNode.textContent = ''; started = true; setStatus(''); }
                    assistantNode.textContent += event.text || '';
                    scrollNode.scrollTop = scrollNode.scrollHeight;
                }
                if (event.type === 'error') throw new Error(event.message || root.dataset.failed);
                if (event.type === 'done') return assistantNode.textContent;
            }
            if (done) break;
        }
        return assistantNode.textContent;
    };
    const send = async (text) => {
        text = text.trim();
        if (!text || controller) return;
        retryText = text;
        addMessage('user', text);
        conversation.push({ role: 'user', content: text });
        const assistantNode = addMessage('assistant', '', true);
        setBusy(true);
        setStatus(root.dataset.thinking);
        controller = new AbortController();
        try {
            const response = await fetch(root.dataset.endpoint, {
                method: 'POST',
                headers: { 'Accept': 'text/event-stream', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrf },
                credentials: 'same-origin',
                body: JSON.stringify({ messages: conversation.slice(-10) }),
                signal: controller.signal,
            });
            const answer = await parseResponse(response, assistantNode);
            assistantNode.removeAttribute('data-pending');
            if (answer.trim()) conversation.push({ role: 'assistant', content: answer });
            else assistantNode.remove();
        } catch (error) {
            assistantNode.remove();
            if (error.name !== 'AbortError') {
                const errorNode = addMessage('error', error.message || root.dataset.failed);
                const retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'assistant-retry';
                retry.textContent = document.documentElement.lang === 'ro' ? 'Reincearca' : 'Retry';
                retry.addEventListener('click', () => { errorNode.remove(); conversation.pop(); send(retryText); });
                errorNode.append(document.createElement('br'), retry);
            }
        } finally {
            controller = null;
            setBusy(false);
            setStatus('');
            input.disabled = false;
            if (panel.open && window.matchMedia('(min-width:801px)').matches) input.focus({ preventScroll: true });
        }
    };
    form.addEventListener('submit', (event) => { event.preventDefault(); const text = input.value; input.value = ''; send(text); });
    input.addEventListener('keydown', (event) => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); form.requestSubmit(); } });
    stopButton.addEventListener('click', () => controller?.abort());
    root.querySelectorAll('[data-assistant-suggestion]').forEach((button) => button.addEventListener('click', () => send(button.textContent)));
})();
