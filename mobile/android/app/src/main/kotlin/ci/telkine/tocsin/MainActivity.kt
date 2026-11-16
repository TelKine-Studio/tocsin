package ci.telkine.tocsin

import android.Manifest
import android.app.NotificationManager
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.provider.Settings
import androidx.core.app.ActivityCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import com.google.firebase.FirebaseApp
import com.google.firebase.messaging.FirebaseMessaging
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

/**
 * Android side of the "tocsin/push" channel (see push_service.dart):
 * permission, Firebase token, notification taps.
 */
class MainActivity : FlutterActivity() {
    private var pendingRegister: MethodChannel.Result? = null
    private var launchPayload: Map<String, Any?>? = null

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        val channel = MethodChannel(flutterEngine.dartExecutor.binaryMessenger, "tocsin/push")
        Push.channel = channel
        launchPayload = Push.payloadFrom(intent)

        channel.setMethodCallHandler { call, result ->
            when (call.method) {
                "register" -> register(result)
                "permissionStatus" -> result.success(permissionStatus())
                "initialNotification" -> {
                    result.success(launchPayload)
                    launchPayload = null
                }
                "deviceName" -> result.success("${Build.MANUFACTURER.replaceFirstChar { it.uppercase() }} ${Build.MODEL}")
                "removeDelivered" -> {
                    val thread = call.argument<String>("thread")
                    val id = call.argument<Int>("id")
                    val manager = getSystemService(NotificationManager::class.java)
                    manager.activeNotifications
                        .filter { (thread == null || it.tag == thread) && (id == null || it.id == id) }
                        .forEach { manager.cancel(it.tag, it.id) }
                    result.success(null)
                }
                "saveSharedToken" -> {
                    Push.saveToken(this, call.argument<String>("server") ?: "", call.argument<String>("token"))
                    result.success(null)
                }
                "openSettings" -> {
                    startActivity(Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, packageName))
                    result.success(null)
                }
                else -> result.notImplemented()
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        Push.payloadFrom(intent)?.let { Push.channel?.invokeMethod("onTap", it) }
    }

    private fun permissionStatus(): String = when {
        FirebaseApp.getApps(this).isEmpty() -> "unsupported"
        NotificationManagerCompat.from(this).areNotificationsEnabled() -> "granted"
        Build.VERSION.SDK_INT >= 33 && ActivityCompat.shouldShowRequestPermissionRationale(this, Manifest.permission.POST_NOTIFICATIONS) -> "denied"
        Build.VERSION.SDK_INT >= 33 -> "notDetermined"
        else -> "denied"
    }

    private fun register(result: MethodChannel.Result) {
        if (FirebaseApp.getApps(this).isEmpty()) {
            result.success(false)
            return
        }
        if (Build.VERSION.SDK_INT >= 33 && ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            pendingRegister = result
            ActivityCompat.requestPermissions(this, arrayOf(Manifest.permission.POST_NOTIFICATIONS), 4242)
            return
        }
        fetchToken()
        result.success(true)
    }

    override fun onRequestPermissionsResult(requestCode: Int, permissions: Array<out String>, grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        if (requestCode != 4242) return
        val granted = grantResults.firstOrNull() == PackageManager.PERMISSION_GRANTED
        if (granted) fetchToken()
        pendingRegister?.success(granted)
        pendingRegister = null
    }

    private fun fetchToken() {
        FirebaseMessaging.getInstance().token.addOnSuccessListener { token -> Push.emitToken(token) }
            .addOnFailureListener { error -> Push.channel?.invokeMethod("onTokenError", error.message) }
    }
}
