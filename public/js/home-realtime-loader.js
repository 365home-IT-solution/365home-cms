let homeRealtimeLoaded = false;
const loadHomeRealtime = () => {
    if (homeRealtimeLoaded) return;
    homeRealtimeLoaded = true;
    import(window.__homeRealtimeUrls.echo);
    import(window.__homeRealtimeUrls.ws);
};
const boundary = document.querySelector('[data-home-realtime-boundary]');
if (boundary && 'IntersectionObserver' in window) {
    const observer = new IntersectionObserver((entries) => {
        if (!entries.some(entry => entry.isIntersecting)) return;
        observer.disconnect();
        loadHomeRealtime();
    });
    observer.observe(boundary);
} else if (boundary) {
    boundary.addEventListener('pointerdown', loadHomeRealtime, { once: true, passive: true });
}
