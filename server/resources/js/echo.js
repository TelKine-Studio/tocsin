import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

/**
 * Realtime connection settings are injected by the server at runtime (see the
 * "tocsin-realtime" meta tag in the layout), so the same build works on every
 * instance without rebuilding assets.
 */
export function createEcho() {
    const meta = document.querySelector('meta[name="tocsin-realtime"]');

    if (!meta) {
        return null;
    }

    let config;

    try {
        config = JSON.parse(meta.content);
    } catch {
        return null;
    }

    if (!config.key) {
        return null;
    }

    window.Pusher = Pusher;

    return new Echo({
        broadcaster: 'reverb',
        key: config.key,
        wsHost: config.host,
        wsPort: config.port,
        wssPort: config.port,
        wsPath: config.path || '',
        forceTLS: config.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
    });
}
