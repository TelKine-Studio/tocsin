import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import focus from '@alpinejs/focus';
import { createEcho } from './echo';

/**
 * Tocsin's web interface is server-rendered (one page per URL).
 * Alpine.js only adds small interactions: menus, dialogs, tabs, toasts,
 * copy buttons and live updates.
 */

window.Alpine = Alpine;
Alpine.plugin(collapse);
Alpine.plugin(focus);

/* ---------------------------------------------------------------- Theme */

Alpine.store('theme', {
    value: localStorage.getItem('theme') || 'system',
    set(value) {
        this.value = value;
        localStorage.setItem('theme', value);
        applyTheme();
    },
});

function applyTheme() {
    const value = localStorage.getItem('theme') || 'system';
    const dark = value === 'dark' || (value === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
}

window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', applyTheme);

/* --------------------------------------------------------------- Toasts */

Alpine.store('toasts', {
    items: [],
    push(message, type = 'success') {
        const id = Date.now() + Math.random();
        this.items.push({ id, message, type });
        setTimeout(() => this.dismiss(id), 5000);
    },
    dismiss(id) {
        this.items = this.items.filter((toast) => toast.id !== id);
    },
});

/* ----------------------------------------------------- Confirm dialogs */

Alpine.store('confirm', {
    open: false,
    title: '',
    message: '',
    action: '',
    form: null,
    ask(form) {
        this.form = form;
        this.title = form.dataset.confirmTitle || form.dataset.confirm;
        this.message = form.dataset.confirmTitle ? form.dataset.confirm : '';
        this.action = form.dataset.confirmAction || '';
        this.open = true;
    },
    accept() {
        this.open = false;
        this.form?.removeAttribute('data-confirm');
        this.form?.submit();
    },
});

// Forms with data-confirm="…" ask for confirmation in a dialog first.
document.addEventListener('submit', (event) => {
    const form = event.target;

    if (form instanceof HTMLFormElement && form.dataset.confirm) {
        event.preventDefault();
        Alpine.store('confirm').ask(form);
    }
});

/* ------------------------------------------------------------ Clipboard */

Alpine.data('copy', (text = null) => ({
    copied: false,
    async copy(value = null) {
        const content = value ?? text ?? this.$refs.source?.textContent.trim() ?? '';

        try {
            await navigator.clipboard.writeText(content);
        } catch {
            const area = Object.assign(document.createElement('textarea'), { value: content });
            document.body.append(area);
            area.select();
            document.execCommand('copy');
            area.remove();
        }

        this.copied = true;
        setTimeout(() => (this.copied = false), 1600);
    },
}));

/* --------------------------------------------------- Message composer */

Alpine.data('composer', (topicLabel) => ({
    title: '',
    body: '',
    priority: '3',
    topicLabel,
    get previewTitle() {
        return this.title.trim() || this.topicLabel;
    },
    get previewSubtitle() {
        return this.title.trim() ? this.topicLabel : '';
    },
}));

/* ---------------------------------------------- Browser notifications */

Alpine.data('webPush', (topic, updateUrl) => ({
    supported: 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window,
    enabled: false,
    busy: false,
    denied: typeof Notification !== 'undefined' && Notification.permission === 'denied',
    async init() {
        if (!this.supported) return;
        const registration = await navigator.serviceWorker.register('/sw.js');
        const subscription = await registration.pushManager.getSubscription();
        if (!subscription) return;
        const response = await this.request('/web-push/status', 'POST', { endpoint: subscription.endpoint });
        this.enabled = (response.topics || []).includes(topic);
    },
    async toggle() {
        if (!this.supported || this.busy) return;
        this.busy = true;
        try {
            const registration = await navigator.serviceWorker.ready;
            let subscription = await registration.pushManager.getSubscription();
            if (!subscription) {
                if ((await Notification.requestPermission()) !== 'granted') {
                    this.denied = true;
                    return;
                }
                const key = document.querySelector('meta[name="tocsin-vapid"]')?.content;
                subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(key) });
            }
            const response = await this.request(updateUrl, 'PUT', { subscription: subscription.toJSON(), enabled: !this.enabled });
            this.enabled = response.enabled;
        } finally {
            this.busy = false;
        }
    },
    async request(url, method, body) {
        const response = await fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify(body),
        });
        return response.ok ? response.json() : {};
    },
}));

function urlBase64ToUint8Array(value) {
    const padding = '='.repeat((4 - (value.length % 4)) % 4);
    const raw = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
    return Uint8Array.from([...raw].map((char) => char.charCodeAt(0)));
}

/* ------------------------------------------------------ Live messages */

Alpine.data('liveTopic', (channelName, isPrivate) => ({
    state: 'connecting',
    init() {
        const echo = createEcho();

        if (!echo) {
            this.state = 'unavailable';
            return;
        }

        echo.connector.pusher.connection.bind('state_change', ({ current }) => (this.state = current));

        if (!channelName) {
            return;
        }

        const channel = isPrivate ? echo.private(channelName) : echo.channel(channelName);
        channel.listen('.message', (message) => this.receive(message));
        channel.listen('.message.updated', (message) => this.refresh(message));
    },
    // Renders the card on the server (acknowledgement button, attachments,
    // grouping counter...). Falls back to the template when it fails.
    async render(message) {
        const url = this.$root.dataset.messageUrl?.replace('__ID__', message.id);

        try {
            const response = await fetch(url, { headers: { Accept: 'text/html' } });
            if (!response.ok) throw new Error(response.status);
            const wrapper = document.createElement('div');
            wrapper.innerHTML = (await response.text()).trim();
            const node = wrapper.firstElementChild;
            if (node) {
                Alpine.initTree(node);
                return node;
            }
        } catch {
            // Use the template below.
        }

        return null;
    },
    async refresh(message) {
        const current = this.$refs.list?.querySelector(`[data-message-id="${message.id}"]`);

        if (!current) {
            return this.receive(message);
        }

        const node = await this.render(message);
        if (node) {
            node.classList.add('animate-pop');
            current.replaceWith(node);
        }
    },
    async receive(message) {
        const list = this.$refs.list;
        const template = document.getElementById('message-template');

        if (!list || !template || list.querySelector(`[data-message-id="${message.id}"]`)) {
            return;
        }

        const rendered = await this.render(message);
        if (rendered && !list.querySelector(`[data-message-id="${message.id}"]`)) {
            rendered.classList.add('animate-pop');
            this.$refs.empty?.remove();
            list.prepend(rendered);
            return;
        }

        const node = template.content.firstElementChild.cloneNode(true);
        const set = (field, value, hide = !value) => {
            const element = node.querySelector(`[data-field="${field}"]`);
            if (element) {
                element.textContent = value ?? '';
                element.hidden = hide;
            }
        };

        node.dataset.messageId = message.id;
        node.dataset.priority = message.priority;
        set('title', message.title);
        set('message', message.message, false);
        set('time', new Date(message.time * 1000).toLocaleString(), false);
        set('tags', (message.tags || []).map((tag) => `#${tag}`).join('  '));
        set('priority', this.$root.dataset[`priority${message.priority}`] || '', message.priority === 3);
        node.classList.add('animate-pop');

        this.$refs.empty?.remove();
        list.prepend(node);
    },
}));

Alpine.start();
