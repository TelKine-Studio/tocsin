import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import focus from '@alpinejs/focus';
import { createEcho } from './echo';
import flatpickr from 'flatpickr';
import { French } from 'flatpickr/dist/l10n/fr.js';
import 'flatpickr/dist/flatpickr.min.css';

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

Alpine.data('pairing', (url) => ({
    svg: null,
    signsIn: false,
    expiresAt: null,
    remaining: '',
    expired: false,
    loading: false,
    error: false,
    timer: null,

    async load() {
        clearInterval(this.timer);
        Object.assign(this, { svg: null, loading: true, error: false, expired: false });
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
            });
            if (!response.ok) throw new Error(response.status);
            const data = await response.json();
            this.svg = data.svg;
            this.signsIn = data.signs_in;
            this.expiresAt = data.expires_at ? new Date(data.expires_at) : null;
            this.countdown();
        } catch {
            this.error = true;
        } finally {
            this.loading = false;
        }
    },

    countdown() {
        if (!this.expiresAt) return;
        const update = () => {
            const seconds = Math.max(0, Math.round((this.expiresAt - Date.now()) / 1000));
            this.remaining = `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
            if (seconds === 0) {
                this.expired = true;
                clearInterval(this.timer);
            }
        };
        update();
        this.timer = setInterval(update, 1000);
    },
}));

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

/* ---------------------------------------------------------- Date picker */

// Date and date-time fields (components/ui/input.blade.php with type
// "date" or "datetime-local"): a styled calendar instead of the browser's.
// The submitted value keeps the native formats (Y-m-d, Y-m-d\TH:i).
Alpine.data('datePicker', (withTime = false, min = null) => ({
    init() {
        const french = document.documentElement.lang.startsWith('fr');
        this.picker = flatpickr(this.$el, {
            locale: french ? French : 'default',
            enableTime: withTime,
            time_24hr: true,
            minuteIncrement: 5,
            dateFormat: withTime ? 'Y-m-d\\TH:i' : 'Y-m-d',
            altInput: true,
            altFormat: withTime ? (french ? 'j F Y à H:i' : 'F j, Y at H:i') : (french ? 'j F Y' : 'F j, Y'),
            minDate: min || null,
            disableMobile: true,
            monthSelectorType: 'static',
        });
    },
    destroy() {
        this.picker?.destroy();
    },
}));

/* --------------------------------------------------------- Custom select */

// Drives components/ui/select.blade.php: a styled listbox in front of a
// hidden native <select> (which still holds the value for forms, x-model
// and onchange handlers).
Alpine.data('selectBox', () => ({
    open: false,
    value: '',
    label: '',
    active: 0,
    options: [],
    init() {
        this.read();
        // x-model may set the native value after this component starts.
        this.$nextTick(() => this.read());
    },
    read() {
        const native = this.$refs.native;
        this.options = [...native.options].map((option) => ({ value: option.value, label: option.text }));
        this.sync();
    },
    sync() {
        const native = this.$refs.native;
        this.value = native.value;
        this.label = native.options[native.selectedIndex]?.text ?? '';
    },
    toggle() {
        this.open ? this.close() : this.show();
    },
    show() {
        this.open = true;
        this.active = Math.max(0, this.options.findIndex((option) => option.value === this.value));
        this.$nextTick(() => {
            this.place();
            this.reveal();
        });
        // The list floats above the page (cards may clip their content): it follows
        // the button while the page scrolls or resizes.
        this.follow = () => (this.open ? this.place() : null);
        window.addEventListener('scroll', this.follow, true);
        window.addEventListener('resize', this.follow);
    },
    close() {
        this.open = false;
        window.removeEventListener('scroll', this.follow, true);
        window.removeEventListener('resize', this.follow);
    },
    place() {
        const list = this.$refs.list;
        const box = this.$refs.button.getBoundingClientRect();
        const gap = 6;
        const below = window.innerHeight - box.bottom - gap - 8;
        const above = box.top - gap - 8;
        const height = Math.min(list.scrollHeight, 256);
        const up = below < height && above > below;

        Object.assign(list.style, {
            position: 'fixed',
            left: `${box.left}px`,
            width: `${box.width}px`,
            marginTop: '0',
            maxHeight: `${Math.max(120, Math.min(256, up ? above : below))}px`,
            top: up ? 'auto' : `${box.bottom + gap}px`,
            bottom: up ? `${window.innerHeight - box.top + gap}px` : 'auto',
        });
    },
    move(step) {
        if (!this.open) return this.show();
        this.active = (this.active + step + this.options.length) % this.options.length;
        this.$nextTick(() => this.reveal());
    },
    pick() {
        this.open ? this.choose(this.options[this.active].value) : this.show();
    },
    reveal() {
        this.$refs.list?.children[this.active + 1]?.scrollIntoView({ block: 'nearest' });
    },
    choose(value) {
        const native = this.$refs.native;
        native.value = value;
        this.sync();
        this.close();
        native.dispatchEvent(new Event('input', { bubbles: true }));
        native.dispatchEvent(new Event('change', { bubbles: true }));
        this.$refs.button.focus();
    },
}));

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
