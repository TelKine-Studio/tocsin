// Inlined in <head> to apply the theme before the first paint.
(() => {
    const value = localStorage.getItem('theme') || 'system';
    const dark = value === 'dark' || (value === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
})();
