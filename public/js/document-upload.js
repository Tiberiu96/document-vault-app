(() => {
    const input = document.getElementById('document-file');
    const zone = document.getElementById('document-dropzone');
    const label = document.getElementById('selected-file');
    if (!input || !zone) return;
    const show = () => {
        label.textContent = input.files[0]?.name || '';
        const title = document.getElementById('title');
        if (title && !title.value && input.files[0]) title.value = input.files[0].name.replace(/\.[^.]+$/, '').slice(0, 160);
    };
    input.form?.addEventListener('submit', async event => {
        if (!window.VaultUploadFlow) return;
        event.preventDefault();
        const file=input.files[0], form=input.form;
        if (!file || form.dataset.sending==='1') return;
        const csrf=form.elements._token.value;
        form.dataset.sending='1'; form.setAttribute('aria-busy','true');
        const progress=form.querySelector('.upload-progress'); if(progress)progress.hidden=false;
        form.querySelectorAll('button[type="submit"]').forEach(button=>button.disabled=true);
        try {
            const response=await fetch(form.action,{method:'POST',body:new FormData(form),credentials:'same-origin',headers:{Accept:'application/json'}});
            const result=await response.json();
            if(!response.ok)throw Object.assign(new Error(Object.values(result.errors||{}).flat()[0]||form.dataset.failed),{userMessage:true});
            await window.VaultUploadFlow.start(file,result,csrf);
        } catch(e) {
            let box=form.querySelector('[data-upload-error]'); if(!box){box=document.createElement('p');box.className='form-alert';box.dataset.uploadError='';form.prepend(box);} box.textContent=e.userMessage?e.message:form.dataset.failed;
            form.dataset.sending='0';form.removeAttribute('aria-busy');if(progress)progress.hidden=true;
            form.querySelectorAll('button[type="submit"]').forEach(button=>button.disabled=false);
        }
    });
    input.addEventListener('change', show);
    ['dragenter', 'dragover'].forEach(type => zone.addEventListener(type, event => {
        event.preventDefault(); zone.classList.add('is-dragging');
    }));
    ['dragleave', 'drop'].forEach(type => zone.addEventListener(type, event => {
        event.preventDefault(); zone.classList.remove('is-dragging');
    }));
    zone.addEventListener('drop', event => {
        if (event.dataTransfer?.files.length === 1) {
            input.files = event.dataTransfer.files;
            show();
        }
    });
})();
