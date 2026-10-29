export function nextIndex(current, total) {
    if (total <= 0) return 0;
    return (current + 1) % total;
}

export function prevIndex(current, total) {
    if (total <= 0) return 0;
    return (current - 1 + total) % total;
}

export function clampInterval(val) {
    const num = parseInt(val, 10);
    if (isNaN(num)) return 4;
    return Math.max(1, Math.min(60, num));
}

