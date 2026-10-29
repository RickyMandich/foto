import { shuffle, wrap, shouldSkipAfterError } from '../lib/audio-queue.js';

export function initAudio(root, tracks) {
    if (!tracks || !tracks.length) return;

    const audio = root.querySelector('#audio-player');
    const controls = root.querySelector('#audio-controls');
    if (!audio || !controls) return;

    const playBtn = controls.querySelector('#audio-play');
    const nextBtn = controls.querySelector('#audio-next');
    const volumeInput = controls.querySelector('#audio-volume');
    const titleEl = controls.querySelector('#audio-title');

    const queue = shuffle(tracks);
    let idx = 0;
    let errorStreak = 0;

    // Ripristina volume
    try {
        const savedVolume = localStorage.getItem('foto.volume');
        if (savedVolume !== null) {
            audio.volume = parseFloat(savedVolume);
            if (volumeInput) volumeInput.value = savedVolume;
        } else {
            audio.volume = 0.6;
        }
    } catch {
        audio.volume = 0.6;
    }

    function updateMediaSession(track) {
        if ('mediaSession' in navigator && window.MediaMetadata) {
            navigator.mediaSession.metadata = new MediaMetadata({
                title: track.title,
                album: 'Foto',
            });
            navigator.mediaSession.setActionHandler('play', () => audio.play().catch(() => {}));
            navigator.mediaSession.setActionHandler('pause', () => audio.pause());
            navigator.mediaSession.setActionHandler('nexttrack', () => {
                errorStreak = 0;
                load(idx + 1);
                audio.play().catch(() => {});
            });
        }
    }

    function load(i) {
        idx = wrap(i, queue.length);
        const currentTrack = queue[idx];
        audio.src = currentTrack.url;
        if (titleEl) {
            titleEl.textContent = currentTrack.title;
            titleEl.title = currentTrack.title;
        }
        updateMediaSession(currentTrack);
    }

    function updateButton() {
        if (!playBtn) return;
        const isPlaying = !audio.paused;
        playBtn.setAttribute('aria-pressed', isPlaying ? 'true' : 'false');
        playBtn.setAttribute('aria-label', isPlaying ? 'Metti in pausa la musica' : 'Riproduci musica');
        playBtn.classList.toggle('text-sky-400', isPlaying);
    }

    playBtn?.addEventListener('click', () => {
        if (audio.paused) {
            audio.play().catch(() => {});
        } else {
            audio.pause();
        }
    });

    nextBtn?.addEventListener('click', () => {
        errorStreak = 0;
        load(idx + 1);
        audio.play().catch(() => {});
    });

    volumeInput?.addEventListener('input', (e) => {
        const val = parseFloat(e.target.value);
        audio.volume = val;
        try {
            localStorage.setItem('foto.volume', val);
        } catch {}
    });

    audio.addEventListener('play', updateButton);
    audio.addEventListener('pause', updateButton);
    audio.addEventListener('playing', () => {
        errorStreak = 0;
        updateButton();
    });

    audio.addEventListener('ended', () => {
        errorStreak = 0;
        load(idx + 1);
        audio.play().catch(() => {});
    });

    audio.addEventListener('error', () => {
        errorStreak++;
        if (shouldSkipAfterError(errorStreak, queue.length)) {
            load(idx + 1);
            audio.play().catch(() => {});
        } else {
            updateButton();
        }
    });

    load(0);
}

