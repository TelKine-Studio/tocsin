import Foundation
import Intents
import Security
import UniformTypeIdentifiers
import UserNotifications

/// Enriches Tocsin notifications before iOS displays them:
/// - confidential mode: downloads the message from the Tocsin server;
/// - image attachments: downloads and attaches the picture;
/// - action buttons: registers a category with the message's actions;
/// - topic logo: shown as a communication notification (like a contact
///   photo), the only way iOS displays another image than the app icon.
final class NotificationService: UNNotificationServiceExtension {
  private var contentHandler: ((UNNotificationContent) -> Void)?
  private var content: UNMutableNotificationContent?

  override func didReceive(
    _ request: UNNotificationRequest,
    withContentHandler contentHandler: @escaping (UNNotificationContent) -> Void
  ) {
    self.contentHandler = contentHandler
    guard let content = request.content.mutableCopy() as? UNMutableNotificationContent else {
      contentHandler(request.content)
      return
    }
    self.content = content

    var info = content.userInfo["tocsin"] as? [String: Any] ?? [:]
    let group = DispatchGroup()

    if (info["fetch"] as? Int) == 1, let server = info["server"] as? String, let topic = info["topic"] as? String, let id = info["id"] as? Int {
      group.enter()
      fetchMessage(server: server, topic: topic, id: id) { message in
        if let message {
          let title = message["title"] as? String
          let topicLabel = content.title
          content.title = (title?.isEmpty == false ? title : topicLabel) ?? topicLabel
          content.subtitle = (title?.isEmpty == false) ? topicLabel : ""
          content.body = message["message"] as? String ?? content.body
          if let attachment = message["attachment"] as? [String: Any], let type = attachment["type"] as? String, type.hasPrefix("image/") {
            info["image"] = attachment["url"]
          }
          if let actions = message["actions"] as? [[String: Any]], !actions.isEmpty {
            info["actions"] = actions
          }
          info["click"] = message["click"]
          var userInfo = content.userInfo
          userInfo["tocsin"] = info
          content.userInfo = userInfo
        }
        group.leave()
      }
    }

    group.notify(queue: .global()) {
      // The message as the server sent it (or as fetched in confidential
      // mode), for the app to display on tap before syncing.
      info["message"] = ["title": content.subtitle.isEmpty ? "" : content.title, "body": content.body]
      var userInfo = content.userInfo
      userInfo["tocsin"] = info
      content.userInfo = userInfo

      self.registerActions(info["actions"] as? [[String: Any]] ?? [], content: content)

      // One more unread message on the app icon (the app sets the exact
      // count again when it opens).
      content.badge = NSNumber(value: SharedBadge.increment())

      let finish = {
        if let icon = info["icon"] as? String, let url = URL(string: icon) {
          self.withTopicLogo(url, topic: info["topic"] as? String ?? "", content: content, completion: contentHandler)
        } else {
          contentHandler(content)
        }
      }

      if let image = info["image"] as? String, let url = URL(string: image) {
        self.attachImage(url) { attachment in
          if let attachment { content.attachments = [attachment] }
          finish()
        }
      } else {
        finish()
      }
    }
  }

  override func serviceExtensionTimeWillExpire() {
    if let contentHandler, let content {
      contentHandler(content)
    }
  }

  // MARK: - Confidential mode

  private func fetchMessage(server: String, topic: String, id: Int, completion: @escaping ([String: Any]?) -> Void) {
    var components = URLComponents(string: "\(server)/api/v1/topics/\(topic)/messages")
    components?.queryItems = [URLQueryItem(name: "since", value: String(id - 1)), URLQueryItem(name: "limit", value: "1")]
    guard let url = components?.url else { return completion(nil) }

    var request = URLRequest(url: url, timeoutInterval: 15)
    request.setValue("application/json", forHTTPHeaderField: "Accept")
    if let token = SharedTokens.read(server: server) {
      request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
    }

    URLSession.shared.dataTask(with: request) { data, _, _ in
      guard
        let data,
        let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any],
        let messages = json["data"] as? [[String: Any]]
      else { return completion(nil) }
      completion(messages.first { ($0["id"] as? Int) == id } ?? messages.first)
    }.resume()
  }

  // MARK: - Image attachments

  private func attachImage(_ url: URL, completion: @escaping (UNNotificationAttachment?) -> Void) {
    URLSession.shared.downloadTask(with: url) { location, response, _ in
      guard let location else { return completion(nil) }
      let ext = UTType(mimeType: response?.mimeType ?? "")?.preferredFilenameExtension ?? url.pathExtension
      let file = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString).appendingPathExtension(ext.isEmpty ? "png" : ext)
      try? FileManager.default.moveItem(at: location, to: file)
      completion(try? UNNotificationAttachment(identifier: "image", url: file))
    }.resume()
  }

  // MARK: - Topic logo

  /// Turns the notification into a communication notification whose
  /// "sender" is the topic, with its logo. Requires the Communication
  /// Notifications capability; without it iOS keeps the plain notification.
  private func withTopicLogo(
    _ url: URL,
    topic: String,
    content: UNMutableNotificationContent,
    completion: @escaping (UNNotificationContent) -> Void
  ) {
    URLSession.shared.dataTask(with: URLRequest(url: url, timeoutInterval: 10)) { data, _, _ in
      guard let data, !data.isEmpty else { return completion(content) }

      // The alert carries the topic name as title (no message title) or
      // as subtitle (message title as title).
      let hasMessageTitle = !content.subtitle.isEmpty
      let topicLabel = hasMessageTitle ? content.subtitle : content.title
      if hasMessageTitle {
        content.subtitle = content.title
      }

      let sender = INPerson(
        personHandle: INPersonHandle(value: topic, type: .unknown),
        nameComponents: nil,
        displayName: topicLabel,
        image: INImage(imageData: data),
        contactIdentifier: nil,
        customIdentifier: "tocsin.topic.\(topic)"
      )
      let intent = INSendMessageIntent(
        recipients: nil,
        outgoingMessageType: .outgoingMessageText,
        content: content.body,
        speakableGroupName: nil,
        conversationIdentifier: topic,
        serviceName: nil,
        sender: sender,
        attachments: nil
      )
      intent.setImage(INImage(imageData: data), forParameterNamed: \.sender)

      let interaction = INInteraction(intent: intent, response: nil)
      interaction.direction = .incoming
      interaction.donate { _ in
        completion((try? content.updating(from: intent)) ?? content)
      }
    }.resume()
  }

  // MARK: - Action buttons

  /// iOS only shows buttons of registered categories: one category is
  /// created per set of labels ("tocsin.actions.<hash>").
  private func registerActions(_ actions: [[String: Any]], content: UNMutableNotificationContent) {
    let labels = actions.prefix(3).compactMap { $0["label"] as? String }
    guard !labels.isEmpty else { return }

    let identifier = "tocsin.actions." + String(labels.joined(separator: "|").hashValue, radix: 36)
    let category = UNNotificationCategory(
      identifier: identifier,
      actions: labels.enumerated().map { index, label in
        UNNotificationAction(identifier: "action-\(index)", title: label, options: [.foreground])
      },
      intentIdentifiers: [],
      options: []
    )

    let semaphore = DispatchSemaphore(value: 0)
    let center = UNUserNotificationCenter.current()
    center.getNotificationCategories { existing in
      var categories = existing.filter { $0.identifier != identifier }
      categories.insert(category)
      center.setNotificationCategories(categories)
      semaphore.signal()
    }
    _ = semaphore.wait(timeout: .now() + 2)
    content.categoryIdentifier = identifier
  }
}

/// Access tokens shared by the app with this extension through the keychain.
enum SharedTokens {
  static let service = "ci.telkine.tocsin.tokens"

  static var accessGroup: String? {
    guard let prefix = Bundle.main.object(forInfoDictionaryKey: "AppIdentifierPrefix") as? String, !prefix.isEmpty else { return nil }
    return prefix + "ci.telkine.tocsin.shared"
  }

  static func read(server: String) -> String? {
    var query: [String: Any] = [
      kSecClass as String: kSecClassGenericPassword,
      kSecAttrService as String: service,
      kSecAttrAccount as String: server,
      kSecReturnData as String: true,
    ]
    if let accessGroup { query[kSecAttrAccessGroup as String] = accessGroup }
    var result: AnyObject?
    guard SecItemCopyMatching(query as CFDictionary, &result) == errSecSuccess, let data = result as? Data else { return nil }
    return String(data: data, encoding: .utf8)
  }
}

/// Unread count of the app icon, shared with the app (see AppDelegate).
enum SharedBadge {
  private static func query() -> [String: Any] {
    var query: [String: Any] = [
      kSecClass as String: kSecClassGenericPassword,
      kSecAttrService as String: "ci.telkine.tocsin.badge",
      kSecAttrAccount as String: "count",
    ]
    if let accessGroup = SharedTokens.accessGroup { query[kSecAttrAccessGroup as String] = accessGroup }
    return query
  }

  /// Adds one and returns the new count.
  static func increment() -> Int {
    var read = query()
    read[kSecReturnData as String] = true
    var result: AnyObject?
    let current = SecItemCopyMatching(read as CFDictionary, &result) == errSecSuccess
      ? (result as? Data).flatMap { Int(String(decoding: $0, as: UTF8.self)) } ?? 0
      : 0
    let count = current + 1

    var item = query()
    SecItemDelete(item as CFDictionary)
    item[kSecValueData as String] = Data(String(count).utf8)
    item[kSecAttrAccessible as String] = kSecAttrAccessibleAfterFirstUnlock
    SecItemAdd(item as CFDictionary, nil)
    return count
  }
}

