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
