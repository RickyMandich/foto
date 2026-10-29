const modules = {
    gallery: () => import('./gallery/index.js'),
};

document.querySelectorAll('[data-module]').forEach((el) => {
    modules[el.dataset.module]?.().then((m) => m.default(el));
});

