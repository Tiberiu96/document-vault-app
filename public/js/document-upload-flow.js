(() => {
    let current = null, stopped = false, pollTimer = null;
    const sameOrigin = url => {
        const target = new URL(url, location.href);
        if (target.origin !== location.origin) throw new Error('origin');
        return target.href;
    };
    const clear = () => { stopped = true; clearTimeout(pollTimer); if(current?.previewUrl)URL.revokeObjectURL(current.previewUrl); current = null; };
    const message = (form, text) => {
        let box = form.querySelector('[data-flow-error]');
        if (!box) { box = document.createElement('div'); box.className = 'form-alert'; box.dataset.flowError = ''; box.setAttribute('role','alert'); form.prepend(box); }
        box.textContent = text;
    };
    const decorate = () => {
        const form = document.querySelector('[data-document-confirm]');
        if (!form || !current || form.dataset.documentId !== current.meta.id) return;
        const source = form.querySelector('[data-source-upload]');
        if (source) {
            source.hidden = true;
            const input = source.querySelector('input'); input.required = false; input.disabled = true;
            const info = form.querySelector('[data-retained-source]'); info.hidden = false; info.querySelector('span').textContent = current.file.name;
            current.previewUrl ||= URL.createObjectURL(current.file);
            info.querySelector('[data-local-preview]').href=current.previewUrl;
            info.querySelector('[data-local-download]').href=current.previewUrl;
            info.querySelector('[data-local-download]').download=current.file.name;
        }
    };
    const render = async url => {
        const target = sameOrigin(url);
        const response = await fetch(target, {credentials:'same-origin',headers:{Accept:'text/html'},cache:'no-store'});
        if (!response.ok || new URL(response.url).origin !== location.origin) throw new Error('view');
        const page = new DOMParser().parseFromString(await response.text(),'text/html');
        const main = page.querySelector('#main');
        if (!main || !main.querySelector('[data-document-confirm]')) { clear(); location.assign(sameOrigin(response.url)); throw new Error('view'); }
        document.querySelector('#main').replaceChildren(...main.childNodes);
        history.replaceState(null,'',target); window.scrollTo(0,0); decorate();
    };
    const cancel = async () => {
        const state = current; if (!state) return;
        const body = new FormData(); body.set('_token',state.csrf); body.set('_method','DELETE');
        const response = await fetch(sameOrigin(state.meta.discard_url),{method:'POST',body,credentials:'same-origin',headers:{Accept:'application/json'}});
        if (!response.ok) throw new Error('cancel');
        const destination = state.meta.index_url; clear(); location.assign(sameOrigin(destination));
    };
    const start = async (file, meta, csrf) => {
        clear(); stopped = false; current = {file,meta,csrf};
        const main = document.querySelector('#main');
        const panel = document.createElement('section'); panel.className='library-panel processing-panel';
        const title=document.createElement('h1'); title.textContent=meta.messages.title;
        const notice=document.createElement('p'); notice.textContent=meta.messages.progress; notice.setAttribute('role','status');
        const spinner=document.createElement('span'); spinner.className='processing-spinner'; spinner.setAttribute('aria-hidden','true');
        const status=document.createElement('p'); status.setAttribute('aria-live','polite');
        const elapsed=document.createElement('p'); elapsed.className='field-help';
        const retry=document.createElement('button'); retry.type='button'; retry.className='button button-outline'; retry.textContent=meta.messages.retry; retry.hidden=true;
        const discard=document.createElement('button'); discard.type='button'; discard.className='inline-link'; discard.textContent=meta.messages.cancel;
        discard.addEventListener('click',()=>{discard.disabled=true;cancel().catch(()=>{notice.textContent=meta.messages.failed;discard.disabled=false;});});
        panel.append(title,spinner,notice,status,elapsed,retry,discard); main.replaceChildren(panel); history.replaceState(null,'',sameOrigin(meta.show_url));
        let count=0;
        const poll=async()=>{
            if(stopped || !current)return;
            try {
                const response=await fetch(sameOrigin(meta.status_url),{credentials:'same-origin',headers:{Accept:'application/json'},cache:'no-store'});
                if([401,403,404,410].includes(response.status)){clear();location.reload();return;}
                if(!response.ok)throw new Error('status');
                const data=await response.json();
                status.textContent=`${meta.messages.ocr}: ${data.ocr_label} · ${meta.messages.ai}: ${data.ai_label}`;
                const seconds=Math.max(0,Number(data.elapsed_seconds)||0); elapsed.textContent=`${Math.floor(seconds/60)}:${String(seconds%60).padStart(2,'0')}`;
                if(!data.pending){await render(data.ready_for_review && meta.review_requested?meta.review_url:meta.edit_url);return;}
            } catch(_) { notice.textContent=meta.messages.failed; }
            if(++count<300)pollTimer=setTimeout(poll,2000);
            else {spinner.hidden=true;retry.hidden=false;}
        };
        retry.addEventListener('click',()=>{count=0;retry.hidden=true;spinner.hidden=false;poll();});
        await poll();
    };
    document.addEventListener('submit', async event => {
        const form=event.target;
        if(!current || !(form instanceof HTMLFormElement))return;
        if(form.id==='discard-upload'){
            event.preventDefault(); const owner=document.querySelector('[data-document-confirm]'), failure=current.meta.messages.failed; cancel().catch(()=>{if(owner)message(owner,failure);}); return;
        }
        if(!form.matches('[data-document-confirm]') || form.dataset.documentId!==current.meta.id)return;
        event.preventDefault(); if(form.dataset.sending==='1')return;
        form.dataset.sending='1'; form.setAttribute('aria-busy','true');
        const buttons=form.querySelectorAll('button[type="submit"]'); buttons.forEach(b=>b.disabled=true);
        try {
            const body=new FormData(form); body.set('file',current.file,current.file.name);
            const response=await fetch(sameOrigin(form.action),{method:'POST',body,credentials:'same-origin',headers:{Accept:'application/json'}});
            const result=await response.json();
            if(!response.ok)throw Object.assign(new Error(Object.values(result.errors||{}).flat()[0]||current.meta.messages.failed),{userMessage:true});
            const target=sameOrigin(result.redirect);clear();location.assign(target);
        } catch(e) {message(form,e.userMessage?e.message:current?.meta.messages.failed);form.dataset.sending='0';form.removeAttribute('aria-busy');buttons.forEach(b=>b.disabled=false);}
    });
    const adopt = async () => {
        const form=document.querySelector('[data-document-confirm][data-source-available="1"]');
        if(!form || current)return;
        try {
            const meta=JSON.parse(atob(form.dataset.uploadState)), csrf=form.elements._token.value;
            const response=await fetch(sameOrigin(meta.source_url),{credentials:'same-origin',cache:'no-store'});
            if(!response.ok || (response.headers.get('Content-Type')||'').split(';')[0]!==meta.file_type)return;
            const blob=await response.blob();
            if(blob.size!==meta.file_bytes)return;
            stopped=false; current={file:new File([blob],meta.file_name,{type:meta.file_type}),meta,csrf};decorate();
            const body=new FormData();body.set('_token',csrf);body.set('bytes',String(blob.size));
            const receipt=await fetch(sameOrigin(meta.source_received_url),{method:'POST',body,credentials:'same-origin',headers:{Accept:'application/json'}});
            if([401,403,404,409,410].includes(receipt.status)){clear();location.reload();}
        } catch(_) { /* Reselectarea manuala ramane disponibila, fara afisarea continutului privat. */ }
    };
    adopt();
    window.addEventListener('beforeunload',event=>{if(current){event.preventDefault();event.returnValue='';}});
    window.VaultUploadFlow = {start};
    window.addEventListener('pagehide',clear);
    window.addEventListener('pageshow',event=>{if(event.persisted)location.reload();});
})();
