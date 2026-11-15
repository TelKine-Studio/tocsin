package ci.telkine.tocsin

import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Intent
import android.graphics.BitmapFactory
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import com.google.firebase.messaging.FirebaseMessagingService
import com.google.firebase.messaging.RemoteMessage
import org.json.JSONArray
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder

/**
 * Receives Tocsin data messages from Firebase and displays them:
 * channel by priority, big picture, action buttons, confidential mode.
 */
class TocsinMessagingService : FirebaseMessagingService() {
    override fun onNewToken(token: String) {
        Push.emitToken(token)
    }

    override fun onMessageReceived(remote: RemoteMessage) {
        val data = remote.data.toMutableMap()
        val server = data["server"] ?: return
        val topic = data["topic"] ?: return

        // Confidential mode: the push only carries the message id.
        if (data["fetch"] == "1") {
            fetch(server, topic, data["id"]?.toIntOrNull() ?: 0)?.let { message ->
                val title = message.optString("title")
                if (title.isNotEmpty()) {
                    data["subtitle"] = data["title"] ?: topic
                    data["title"] = title
                }
                data["body"] = message.optString("message", data["body"] ?: "")
                message.optJSONArray("actions")?.let { data["actions"] = it.toString() }
                message.optJSONObject("attachment")?.takeIf { it.optString("type").startsWith("image/") }?.let { data["image"] = it.optString("url") }
            }
        }

        Push.emit("onMessage", mapOf("server" to server, "topic" to topic, "id" to data["id"]))
        show(data, server, topic)
    }

    private fun show(data: Map<String, String>, server: String, topic: String) {
        val priority = data["priority"]?.toIntOrNull() ?: 3
        val channelId = ensureChannel(priority)
        val id = (data["id"]?.toIntOrNull() ?: System.currentTimeMillis().toInt())

        val builder = NotificationCompat.Builder(this, channelId)
            .setSmallIcon(R.mipmap.ic_launcher)
            .setContentTitle(data["title"] ?: topic)
            .setContentText(data["body"])
            .setSubText(data["subtitle"])
            .setAutoCancel(true)
            .setGroup(topic)
            .setPriority(if (priority >= 4) NotificationCompat.PRIORITY_HIGH else if (priority <= 2) NotificationCompat.PRIORITY_LOW else NotificationCompat.PRIORITY_DEFAULT)
            .setContentIntent(intent(server, topic, data["actions"], -1, id))
            .setStyle(NotificationCompat.BigTextStyle().bigText(data["body"]))

        // Logo of the topic (or X-Icon of the message), shown as large icon.
        val icon = data["icon"]?.let { url ->
            runCatching { URL(url).openStream().use { BitmapFactory.decodeStream(it) } }.getOrNull()
        }
        icon?.let { builder.setLargeIcon(it) }

        data["image"]?.let { url ->
            runCatching { URL(url).openStream().use { BitmapFactory.decodeStream(it) } }.getOrNull()?.let { bitmap ->
                builder.setLargeIcon(icon ?: bitmap).setStyle(NotificationCompat.BigPictureStyle().bigPicture(bitmap).setSummaryText(data["body"]))
            }
        }

        data["actions"]?.let { json ->
            runCatching { JSONArray(json) }.getOrNull()?.let { actions ->
                for (index in 0 until minOf(actions.length(), 3)) {
                    val label = actions.getJSONObject(index).optString("label")
                    builder.addAction(0, label, intent(server, topic, json, index, id * 10 + index + 1))
                }
            }
        }

        runCatching { NotificationManagerCompat.from(this).notify(topic, id, builder.build()) }
    }

    private fun intent(server: String, topic: String, actions: String?, actionIndex: Int, requestCode: Int): PendingIntent {
        val intent = Intent(this, MainActivity::class.java).apply {
            flags = Intent.FLAG_ACTIVITY_SINGLE_TOP or Intent.FLAG_ACTIVITY_CLEAR_TOP
            putExtra("tocsin.server", server)
            putExtra("tocsin.topic", topic)
            putExtra("tocsin.actions", actions)
            putExtra("tocsin.actionIndex", actionIndex)
        }
        return PendingIntent.getActivity(this, requestCode, intent, PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE)
    }

    private fun ensureChannel(priority: Int): String {
        val (id, name, importance) = when {
            priority >= 4 -> Triple("tocsin_high", getString(R.string.channel_high), NotificationManager.IMPORTANCE_HIGH)
            priority <= 2 -> Triple("tocsin_low", getString(R.string.channel_low), NotificationManager.IMPORTANCE_LOW)
            else -> Triple("tocsin_default", getString(R.string.channel_default), NotificationManager.IMPORTANCE_DEFAULT)
        }
        getSystemService(NotificationManager::class.java).createNotificationChannel(NotificationChannel(id, name, importance))
        return id
    }

    private fun fetch(server: String, topic: String, id: Int): JSONObject? = runCatching {
        val url = URL("$server/api/v1/topics/${URLEncoder.encode(topic, "UTF-8")}/messages?since=${id - 1}&limit=1")
        val connection = (url.openConnection() as HttpURLConnection).apply {
            connectTimeout = 10_000
            readTimeout = 10_000
            setRequestProperty("Accept", "application/json")
            Push.token(this@TocsinMessagingService, server)?.let { setRequestProperty("Authorization", "Bearer $it") }
        }
        connection.inputStream.bufferedReader().use { JSONObject(it.readText()) }.optJSONArray("data")?.optJSONObject(0)
    }.getOrNull()
}
