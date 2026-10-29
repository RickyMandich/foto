export function shuffle(items, random = Math.random) {
    const a = [...items];
    for (let i = a.length - 1; i > 0; i--) {
        const j = Math.floor(random() * (i + 1));
        [a[i], a[j]] = [a[j], a[i]];
    }
    return a;
}

export function wrap(index, length) {
    if (length <= 0) return 0;
    return ((index % length) + length) % length;
}

/** Ha senso continuare a saltare dopo un errore? Falso quando tutti i brani sono falliti di fila. */
export function shouldSkipAfterError(errorStreak, queueLength) {
    return errorStreak < queueLength;
}

