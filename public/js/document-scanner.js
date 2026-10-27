(() => {
    const root = document.querySelector('[data-scanner]');
    if (!root) return;
    const find = name => root.querySelector(`[data-scan-${name}]`);
    const canvas = find('canvas'), video = find('video'), form = find('submit');
    const pages = [], sliders = [...root.querySelectorAll('[data-crop]')];
    let active = -1, bitmap = null, stream = null, busy = false, generation = 0;
    const error = text => { find('error').textContent = text; find('error').hidden = !text; };
    const stopCamera = () => { stream?.getTracks().forEach(track => track.stop()); stream = null; video.srcObject = null; video.hidden = true; find('shoot').hidden = true; };
    const blobFrom = target => new Promise((resolve, reject) => target.toBlob(blob => blob ? resolve(blob) : reject(new Error('image')), 'image/jpeg', .84));
    const load = async blob => {
        if (window.createImageBitmap) return createImageBitmap(blob, {imageOrientation:'from-image'});
        const url = URL.createObjectURL(blob), image = new Image();
        try { image.src = url; await image.decode(); return image; } finally { URL.revokeObjectURL(url); }
    };
    const bounds = () => {
        const v = Object.fromEntries(sliders.map(s => [s.dataset.crop, Number(s.value) / 100]));
        return {x:canvas.width * v.left, y:canvas.height * v.top, w:canvas.width * (1 - v.left - v.right), h:canvas.height * (1 - v.top - v.bottom)};
    };
    const draw = () => {
        if (!bitmap) return;
        const ctx = canvas.getContext('2d'), b = bounds();
        ctx.drawImage(bitmap, 0, 0);
        ctx.fillStyle = 'rgba(36,39,36,.55)';
        ctx.fillRect(0,0,canvas.width,b.y); ctx.fillRect(0,b.y+b.h,canvas.width,canvas.height-b.y-b.h);
        ctx.fillRect(0,b.y,b.x,b.h); ctx.fillRect(b.x+b.w,b.y,canvas.width-b.x-b.w,b.h);
        ctx.strokeStyle='#bca47c'; ctx.lineWidth=Math.max(3, canvas.width/400); ctx.strokeRect(b.x,b.y,b.w,b.h);
    };
    const refresh = () => {
        find('count').textContent = `${pages.length} / ${root.dataset.maxPages}`;
        form.querySelector('button[type="submit"]').disabled = busy || !pages.length;
        ['crop','rotate','remove'].forEach(name => { find(name).disabled = busy || active < 0; });
        const list = find('pages'); list.replaceChildren();
        pages.forEach((page, i) => {
            const button = document.createElement('button'); button.type='button'; button.disabled=busy;
            button.setAttribute('aria-label', `${root.dataset.pageLabel} ${i+1}`); button.setAttribute('aria-pressed', String(i===active));
            const image = document.createElement('img'); image.src=page.url; image.alt='';
            const number = document.createElement('span'); number.textContent=String(i+1);
            button.append(image,number); button.addEventListener('click',()=>select(i)); list.append(button);
        });
    };
    const select = async index => {
        stopCamera(); active=index; sliders.forEach(s=>s.value='0');
        const current=++generation;
        bitmap?.close?.(); bitmap=null;
        canvas.hidden = index < 0; find('empty').hidden = index >= 0;
        refresh();
        if (index < 0) return;
        const image=await load(pages[index].blob);
        if (current!==generation) { image.close?.(); return; }
        bitmap=image; canvas.width=image.width; canvas.height=image.height; draw();
    };
    const replace = async blob => {
        const index=active;
        URL.revokeObjectURL(pages[index].url); pages[index]={blob,url:URL.createObjectURL(blob)};
        await select(index);
    };
    const crop = async () => {
        if (!bitmap || !sliders.some(s=>Number(s.value)>0)) return;
        const b=bounds(), out=document.createElement('canvas'); out.width=Math.round(b.w); out.height=Math.round(b.h);
        out.getContext('2d').drawImage(bitmap,b.x,b.y,b.w,b.h,0,0,out.width,out.height);
        await replace(await blobFrom(out));
    };
    const add = async blob => {
        if (pages.length >= Number(root.dataset.maxPages)) throw new Error('limit');
        if (blob.size > 20*1024*1024 || !['image/jpeg','image/png'].includes(blob.type)) throw new Error('invalid');
        const image=await load(blob);
        if (image.width*image.height>25000000) { image.close?.(); throw new Error('invalid'); }
        const scale=Math.min(1,1800/Math.max(image.width,image.height));
        const out=document.createElement('canvas'); out.width=Math.round(image.width*scale); out.height=Math.round(image.height*scale);
        const ctx=out.getContext('2d'); ctx.fillStyle='white'; ctx.fillRect(0,0,out.width,out.height); ctx.drawImage(image,0,0,out.width,out.height); image.close?.();
        const normalized=await blobFrom(out);
        if (pages.reduce((sum,p)=>sum+p.blob.size,0)+normalized.size>Number(root.dataset.maxBytes)) throw new Error('limit');
        pages.push({blob:normalized,url:URL.createObjectURL(normalized)}); await select(pages.length-1);
    };
    const guarded = async action => {
        if (busy) return;
        busy=true; refresh(); error('');
        try { await action(); } catch(e) { error(e.message==='limit'?root.dataset.limit:root.dataset.invalid); }
        finally { busy=false; refresh(); }
    };
    [find('file'),find('camera-file')].forEach(input=>input.addEventListener('change',()=>guarded(async()=>{
        for (const file of input.files) await add(file);
        input.value='';
    })));
    find('pick').addEventListener('click',()=>{ if(!busy)find('file').click(); });
    find('camera').addEventListener('click',async()=>{
        if (busy) return;
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) { error(root.dataset.fallback); find('camera-file').click(); return; }
        try {
            stopCamera(); stream=await navigator.mediaDevices.getUserMedia({audio:false,video:{facingMode:{ideal:'environment'},width:{ideal:1920},height:{ideal:1080}}});
            video.srcObject=stream; video.hidden=false; canvas.hidden=true; find('empty').hidden=true; find('shoot').hidden=false; await video.play();
        } catch(_) { stopCamera(); error(root.dataset.fallback); }
    });
    find('shoot').addEventListener('click',()=>guarded(async()=>{
        if (!video.videoWidth) throw new Error('invalid');
        const out=document.createElement('canvas'); out.width=video.videoWidth; out.height=video.videoHeight;
        out.getContext('2d').drawImage(video,0,0); await add(await blobFrom(out));
    }));
    find('crop').addEventListener('click',()=>guarded(crop));
    find('rotate').addEventListener('click',()=>guarded(async()=>{
        if (!bitmap) return;
        const out=document.createElement('canvas'); out.width=bitmap.height; out.height=bitmap.width;
        const ctx=out.getContext('2d'); ctx.translate(out.width,0); ctx.rotate(Math.PI/2); ctx.drawImage(bitmap,0,0);
        await replace(await blobFrom(out));
    }));
    find('remove').addEventListener('click',()=>guarded(async()=>{
        if (active<0) return;
        URL.revokeObjectURL(pages[active].url); pages.splice(active,1); await select(Math.min(active,pages.length-1));
    }));
    sliders.forEach(s=>s.addEventListener('input',draw));
    form.addEventListener('submit',async event=>{
        event.preventDefault(); if(busy || !pages.length)return;
        busy=true; refresh(); error(''); find('loading').hidden=false; form.setAttribute('aria-busy','true');
        try {
            await crop(); stopCamera();
            const data=new FormData(form);
            data.set('analyze_with_ai', form.elements.analyze_with_ai?.checked?'1':'0');
            if (!form.elements.analyze_with_ai) data.delete('analyze_with_ai');
            pages.forEach((p,i)=>data.append('pages[]',p.blob,`page-${i+1}.jpg`));
            const response=await fetch(form.action,{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}});
            if (response.status===413) throw new Error(root.dataset.limit);
            const result=await response.json();
            if(!response.ok) throw new Error(Object.values(result.errors||{}).flat()[0] || root.dataset.failed);
            const target=new URL(result.redirect,location.href);
            if(target.origin!==location.origin) throw new Error(root.dataset.failed);
            location.assign(target.href);
        } catch(e) { error(e.message || root.dataset.failed); busy=false; find('loading').hidden=true; form.removeAttribute('aria-busy'); refresh(); }
    });
    window.addEventListener('pagehide',()=>{ stopCamera(); bitmap?.close?.(); pages.forEach(p=>URL.revokeObjectURL(p.url)); });
    refresh();
})();
