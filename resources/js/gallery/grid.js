import { replaceParams } from './url-state.js';

export function initGrid(root, photos, { openAt }) {
    const gridView = root.querySelector('#grid-view');
    if (!gridView) return;

    gridView.querySelectorAll('a[data-index]').forEach((link) => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const index = parseInt(link.dataset.index, 10);
            if (!isNaN(index)) {
                openAt(index);
            }
        });
    });
}

