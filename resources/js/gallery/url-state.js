export function replaceParams(params) {
    const url = new URL(window.location.href);
    for (const [key, val] of Object.entries(params)) {
        if (val === null || val === undefined || val === '') {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, val);
        }
    }
    window.history.replaceState({}, '', url.toString());
}

