import Flutter
import Security
import UIKit
import UserNotifications

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    // FlutterAppDelegate forwards notification callbacks to the plugins.
    UNUserNotificationCenter.current().delegate = self
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)
    if let registrar = engineBridge.pluginRegistry.registrar(forPlugin: "TocsinPushPlugin") {
      TocsinPushPlugin.register(with: registrar)
    }
  }
}

/// Bridges Apple Push Notification service to Dart over the "tocsin/push"
/// method channel: permission, device token, foreground presentation and taps.
final class TocsinPushPlugin: NSObject, FlutterPlugin {
  private let channel: FlutterMethodChannel
  private var launchNotification: [String: Any]?

  init(channel: FlutterMethodChannel) {
    self.channel = channel
  }

  static func register(with registrar: FlutterPluginRegistrar) {
    let channel = FlutterMethodChannel(name: "tocsin/push", binaryMessenger: registrar.messenger())
    let instance = TocsinPushPlugin(channel: channel)
    registrar.addMethodCallDelegate(instance, channel: channel)
    registrar.addApplicationDelegate(instance)
  }

  func handle(_ call: FlutterMethodCall, result: @escaping FlutterResult) {
    switch call.method {
    case "register":
      UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .sound, .badge]) { granted, _ in
        DispatchQueue.main.async {
          if granted { UIApplication.shared.registerForRemoteNotifications() }
          result(granted)
        }
      }
    case "permissionStatus":
      UNUserNotificationCenter.current().getNotificationSettings { settings in
        DispatchQueue.main.async {
          switch settings.authorizationStatus {
          case .authorized, .provisional, .ephemeral: result("granted")
          case .denied: result("denied")
          default: result("notDetermined")
          }
        }
      }
    case "initialNotification":
      result(launchNotification)
      launchNotification = nil
    case "deviceName":
      result(UIDevice.current.name)
    case "removeDelivered":
      let args = call.arguments as? [String: Any]
      let thread = args?["thread"] as? String
      let id = args?["id"] as? Int
      UNUserNotificationCenter.current().getDeliveredNotifications { notifications in
        let ids = notifications
          .filter { thread == nil || $0.request.content.threadIdentifier == thread }
          .filter { notification in
            guard let id else { return true }
            let info = notification.request.content.userInfo["tocsin"] as? [String: Any]
            return (info?["id"] as? Int) == id
          }
          .map { $0.request.identifier }
        UNUserNotificationCenter.current().removeDeliveredNotifications(withIdentifiers: ids)
        DispatchQueue.main.async { result(nil) }
      }
    case "saveSharedToken":
      // Lets the notification extension read the token (confidential mode).
      let args = call.arguments as? [String: Any] ?? [:]
      if let server = args["server"] as? String {
        Self.saveSharedToken(server: server, token: args["token"] as? String)
      }
      result(nil)
    case "setBadge":
      // Unread messages on the app icon. Also shared with the notification
      // extension, which increments it when a push arrives.
      let count = max(0, (call.arguments as? [String: Any])?["count"] as? Int ?? 0)
      SharedBadge.write(count)
      if #available(iOS 16.0, *) {
        UNUserNotificationCenter.current().setBadgeCount(count)
      } else {
        UIApplication.shared.applicationIconBadgeNumber = count
      }
      result(nil)
    case "openSettings":
      if let url = URL(string: UIApplication.openSettingsURLString) {
        UIApplication.shared.open(url)
      }
      result(nil)
    default:
      result(FlutterMethodNotImplemented)
    }
  }

  // MARK: - Remote notifications

  func application(_ application: UIApplication, didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) {
    let token = deviceToken.map { String(format: "%02x", $0) }.joined()
    channel.invokeMethod("onToken", arguments: ["token": token, "environment": Self.apsEnvironment()])
  }

  func application(_ application: UIApplication, didFailToRegisterForRemoteNotificationsWithError error: Error) {
    channel.invokeMethod("onTokenError", arguments: error.localizedDescription)
  }

  func userNotificationCenter(
    _ center: UNUserNotificationCenter,
    willPresent notification: UNNotification,
    withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void
  ) {
    channel.invokeMethod("onMessage", arguments: Self.payload(notification))
    completionHandler([.banner, .list, .sound])
  }

  func userNotificationCenter(
    _ center: UNUserNotificationCenter,
    didReceive response: UNNotificationResponse,
    withCompletionHandler completionHandler: @escaping () -> Void
  ) {
    var payload = Self.payload(response.notification)
    if response.actionIdentifier.hasPrefix("action-"), let index = Int(response.actionIdentifier.dropFirst(7)) {
      payload["actionIndex"] = index
    }
    launchNotification = payload
    channel.invokeMethod("onTap", arguments: payload)
    completionHandler()
  }

  private static func saveSharedToken(server: String, token: String?) {
    var query: [String: Any] = [
      kSecClass as String: kSecClassGenericPassword,
      kSecAttrService as String: "ci.telkine.tocsin.tokens",
      kSecAttrAccount as String: server,
    ]
    if let prefix = Bundle.main.object(forInfoDictionaryKey: "AppIdentifierPrefix") as? String, !prefix.isEmpty {
      query[kSecAttrAccessGroup as String] = prefix + "ci.telkine.tocsin.shared"
    }
    SecItemDelete(query as CFDictionary)
    guard let token, let data = token.data(using: .utf8) else { return }
    query[kSecValueData as String] = data
    query[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlock
    SecItemAdd(query as CFDictionary, nil)
  }

  /// The "tocsin" dictionary added by the server to every push, with the
  /// message as displayed: the app shows it right away, before syncing.
  private static func payload(_ notification: UNNotification) -> [String: Any] {
    let content = notification.request.content
    var payload = (content.userInfo["tocsin"] as? [String: Any]) ?? [:]
    if payload["message"] == nil {
      // Set by the server: message title as title, topic as subtitle.
      let hasTitle = !content.subtitle.isEmpty
      payload["message"] = ["title": hasTitle ? content.title : "", "body": content.body]
    }
    payload["time"] = Int(notification.date.timeIntervalSince1970)
    return payload
  }

  /// APNs environment of this build, read from the embedded provisioning
  /// profile: development builds use the sandbox, App Store builds production.
  private static func apsEnvironment() -> String {
    #if targetEnvironment(simulator)
      return "sandbox"
    #else
      guard
        let path = Bundle.main.path(forResource: "embedded", ofType: "mobileprovision"),
        let data = FileManager.default.contents(atPath: path),
        let content = String(data: data, encoding: .isoLatin1),
        let start = content.range(of: "<plist"),
        let end = content.range(of: "</plist>"),
        let plistData = String(content[start.lowerBound..<end.upperBound]).data(using: .isoLatin1),
        let plist = try? PropertyListSerialization.propertyList(from: plistData, format: nil) as? [String: Any],
        let entitlements = plist["Entitlements"] as? [String: Any],
        let environment = entitlements["aps-environment"] as? String
      else {
        return "production"
      }
      return environment == "development" ? "sandbox" : "production"
    #endif
  }
}

/// Unread count shown on the app icon, shared with the notification
/// extension through the keychain group (no App Group needed).
enum SharedBadge {
  private static func query() -> [String: Any] {
    var query: [String: Any] = [
      kSecClass as String: kSecClassGenericPassword,
      kSecAttrService as String: "ci.telkine.tocsin.badge",
      kSecAttrAccount as String: "count",
    ]
    if let prefix = Bundle.main.object(forInfoDictionaryKey: "AppIdentifierPrefix") as? String, !prefix.isEmpty {
      query[kSecAttrAccessGroup as String] = prefix + "ci.telkine.tocsin.shared"
    }
    return query
  }

  static func write(_ count: Int) {
    var item = query()
    SecItemDelete(item as CFDictionary)
    item[kSecValueData as String] = Data(String(count).utf8)
    item[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlock
    SecItemAdd(item as CFDictionary, nil)
  }
}

