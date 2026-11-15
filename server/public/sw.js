// Tocsin service worker: shows browser notifications (Web Push).
self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { body: event.data?.text() };
    }

    event.waitUntil(
        self.registration.showNotification(data.title || 'Tocsin', {
            body: data.body || '',
            icon: data.icon || '/favicon.svg',
            badge: '/favicon.svg',
            tag: data.tag,
            // Acknowledgements replace the alert silently; reminders ring again.
            renotify: data.renotify ?? (data.priority || 3) > 2,
            silent: (data.priority || 3) <= 2,
            requireInteraction: (data.priority || 3) >= 5,
            data: { url: data.url || '/' },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data?.url || '/';

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            const existing = windows.find((w) => w.url.startsWith(url));
            return existing ? existing.focus() : clients.openWindow(url);
        }),
    );
});
