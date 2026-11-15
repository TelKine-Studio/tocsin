# Tocsin — iPhone app

Flutter application to subscribe to Tocsin topics and receive **push notifications** on iPhone and Android.

## Features

- Real push notifications — Apple (APNs) on iOS, Firebase Cloud Messaging on Android — even when the app is closed.
- Rich notifications: images, action buttons (open a URL or send an HTTP request), confidential mode (content downloaded from your server by the iOS Notification Service Extension / Android service).
- Two-factor login.
- Several servers, each with an optional account (tokens kept in the Keychain).
- Public and private topics; the app asks for credentials when a topic is private.
- Message history updated in real time over WebSocket while the app is open.
- Priorities (time-sensitive, passive…), tags, links, mute per topic, unread counters.
- English and French.

## How push works

```
app ──PUT /api/v1/devices (APNs token + topics)──► Tocsin server
script ──POST /api/v1/topics/{topic}/messages──► Tocsin server ──HTTP/2──► APNs ──► iPhone
```

The native part lives in [`ios/Runner/AppDelegate.swift`](ios/Runner/AppDelegate.swift) (`TocsinPushPlugin`): it requests the permission, obtains the device token, detects the APNs environment from the provisioning profile, shows notifications while the app is in the foreground and forwards taps to Dart (`lib/services/push_service.dart`). No Firebase SDK is involved.

## Structure

```
lib/
├── main.dart                     # Bootstrap, theme, notification taps
├── models/models.dart            # Server, Subscription, TopicMessage…
├── services/
│   ├── api_client.dart           # REST API client (topics, messages, devices)
│   ├── push_service.dart         # APNs bridge (method channel "tocsin/push")
│   ├── realtime_service.dart     # Pusher-protocol WebSocket (Laravel Reverb)
│   ├── sync_service.dart         # Fetch missed messages into the history
│   └── storage.dart              # Preferences + Keychain
├── state/app_state.dart          # Application state, device registration
├── screens/                      # Home, subscribe, topic, settings, login
└── l10n/strings.dart             # Translations
```

## Building for your iPhone

Push notifications require an [Apple Developer Program](https://developer.apple.com/programs/) membership.

1. Open `ios/Runner.xcworkspace` in Xcode.
2. In **Signing & Capabilities**, select your team and set your **bundle identifier** (default `ci.telkine.tocsin`). The **Push Notifications** capability is already declared in `Runner.entitlements`.
3. Create an APNs key (Apple Developer → Keys) and enter it, with the same bundle identifier, in your server under **Administration → Settings → Push notifications**.
4. Run on a device: `flutter run --release` (or archive for TestFlight).

Development builds register with the APNs *sandbox*, TestFlight / App Store builds with *production*; the app reports it automatically.

## Android

Push notifications use Firebase Cloud Messaging. Put the `google-services.json` of your Firebase project (package `ci.telkine.tocsin`) in `android/app/` — see `google-services.json.example`. Without it, the app builds and runs without push. The native part is in `android/app/src/main/kotlin/ci/telkine/tocsin/` (`TocsinMessagingService` displays notifications with a channel per priority, big pictures and action buttons).

In direct mode, upload the matching Firebase service account JSON in the server's push settings.

## iOS Notification Service Extension

`ios/TocsinNotificationService` downloads images, adds action buttons and, in confidential mode, fetches the message content from the server. It shares the access tokens with the app through the keychain group `$(AppIdentifierPrefix)ci.telkine.tocsin.shared`: enable the **Keychain Sharing** capability for both targets with your team.

## Development

```bash
flutter pub get
flutter test
flutter analyze
flutter run
```

To test against a server running on your computer, use your machine's LAN address (e.g. `http://192.168.1.20:8000`) on a real device; the iOS simulator can use `http://localhost:8000`. Remote notifications can be simulated on the simulator with `xcrun simctl push booted ci.telkine.tocsin payload.apns` once notifications are allowed.

## Topic logos on iOS

iOS always shows the app icon in notifications. To show the logo of a topic, the Notification Service Extension turns the notification into a **communication notification** (the topic is the "sender", like a contact photo in Messages). This requires the **Communication Notifications** capability on the `ci.telkine.tocsin` App ID (Apple Developer account → Identifiers) and in the provisioning profile; it is declared in `ios/Runner/Runner.entitlements`. Without it, notifications are displayed normally, without the logo.
