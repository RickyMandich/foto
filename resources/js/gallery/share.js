export function initShare(root) {
    const button = root.querySelector('#share-photo');
    if (!button) return;

    if (!navigator.share && !navigator.clipboard) {
        button.classList.add('hidden');
        return;
    }

    let current = {
        title: document.title,
    };

    root.addEventListener('gallery:change', (e) => {
        current = {
            title: document.title,
        };
    });

    button.addEventListener('click', async () => {
        const url = window.location.href;
        if (navigator.share) {
            try {
                await navigator.share({ title: current.title, url });
            } catch {}
        } else if (navigator.clipboard) {
            try {
                await navigator.clipboard.writeText(url);
                button.classList.add('text-sky-400');
                setTimeout(() => {
                    button.classList.remove('text-sky-400');
                }, 2000);
            } catch {}
        }
    });
}

