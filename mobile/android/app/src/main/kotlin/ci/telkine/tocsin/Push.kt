package ci.telkine.tocsin

import android.content.Context
import android.content.Intent
import android.os.Handler
import android.os.Looper
import io.flutter.plugin.common.MethodChannel
import org.json.JSONArray

/** Shared state between the activity and the messaging service. */
object Push {
    var channel: MethodChannel? = null
    private val main = Handler(Looper.getMainLooper())

    fun emitToken(token: String) = main.post {
        channel?.invokeMethod("onToken", mapOf("token" to token, "environment" to "production", "platform" to "android"))
    }

    fun emit(method: String, payload: Map<String, Any?>) = main.post { channel?.invokeMethod(method, payload) }

    /** Notification payload carried by the intent that opened the app. */
    fun payloadFrom(intent: Intent?): Map<String, Any?>? {
        val extras = intent?.extras ?: return null
        val topic = extras.getString("tocsin.topic") ?: return null
        val actions = extras.getString("tocsin.actions")?.let { json ->
            runCatching {
                val array = JSONArray(json)
                (0 until array.length()).map { i ->
                    val item = array.getJSONObject(i)
                    item.keys().asSequence().associateWith { item.get(it).toString() }
                }
            }.getOrNull()
        }
        return mapOf(
            "server" to extras.getString("tocsin.server"),
            "topic" to topic,
            "actions" to actions,
            "actionIndex" to extras.getInt("tocsin.actionIndex", -1).takeIf { it >= 0 },
            "id" to extras.getString("tocsin.id"),
            "priority" to extras.getString("tocsin.priority"),
            "click" to extras.getString("tocsin.click"),
            "image" to extras.getString("tocsin.image"),
            "time" to extras.getLong("tocsin.time", 0).takeIf { it > 0 },
            "notice" to extras.getString("tocsin.notice"),
            "message" to extras.getString("tocsin.body")?.let {
                mapOf("title" to (extras.getString("tocsin.title") ?: ""), "body" to it)
            },
        )
    }

    private fun prefs(context: Context) = context.getSharedPreferences("tocsin.push", Context.MODE_PRIVATE)

    fun saveToken(context: Context, server: String, token: String?) {
        prefs(context).edit().apply { if (token == null) remove("token:$server") else putString("token:$server", token) }.apply()
    }

    fun token(context: Context, server: String): String? = prefs(context).getString("token:$server", null)
}
