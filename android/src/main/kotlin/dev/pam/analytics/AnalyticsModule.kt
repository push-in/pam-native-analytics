package dev.pam.analytics

import android.content.Context
import android.os.Bundle
import com.google.firebase.FirebaseApp
import com.google.firebase.analytics.FirebaseAnalytics
import dev.pam.nativeapp.modules.ModuleCompletion
import dev.pam.nativeapp.modules.ModuleResultStatus
import dev.pam.nativeapp.modules.NativeModule
import dev.pam.nativeapp.protocol.WireMap
import dev.pam.nativeapp.protocol.WireValue

/**
 * Firebase Analytics without advertising identifiers. A build without
 * Firebase configuration (no google-services.json, so no default app) answers
 * every call with `available = false` and does nothing.
 */
class AnalyticsModule(
    context: Context,
) : NativeModule {
    private val applicationContext = context.applicationContext

    override fun invoke(method: String, payload: ByteArray, completion: ModuleCompletion) {
        val values = runCatching { WireMap.decode(payload) }.getOrElse {
            completion.failure(it)
            return
        }
        val analytics = analytics() ?: run {
            completion.success(available = false)
            return
        }
        runCatching {
            when (method) {
                "setConsent" -> setConsent(analytics, values)
                "setCollectionEnabled" -> analytics.setAnalyticsCollectionEnabled(values.flag("enabled"))
                "logEvent" -> analytics.logEvent(values.text("event"), parameters(values))
                "setScreen" -> analytics.logEvent(
                    FirebaseAnalytics.Event.SCREEN_VIEW,
                    Bundle().apply {
                        putString(FirebaseAnalytics.Param.SCREEN_NAME, values.text("screen"))
                        putString(FirebaseAnalytics.Param.SCREEN_CLASS, values.text("screenClass"))
                    },
                )
                else -> throw IllegalArgumentException("Unknown method: $method")
            }
        }.onSuccess { completion.success() }.onFailure { completion.failure(it) }
    }

    private fun analytics(): FirebaseAnalytics? =
        if (FirebaseApp.getApps(applicationContext).isEmpty()) null
        else runCatching { FirebaseAnalytics.getInstance(applicationContext) }.getOrNull()

    private fun setConsent(analytics: FirebaseAnalytics, values: Map<String, WireValue>) {
        val granted = FirebaseAnalytics.ConsentStatus.GRANTED
        val denied = FirebaseAnalytics.ConsentStatus.DENIED
        // Advertising signals are denied whatever PHP sends.
        analytics.setConsent(mapOf(
            FirebaseAnalytics.ConsentType.ANALYTICS_STORAGE to if (values.flag("analyticsStorage")) granted else denied,
            FirebaseAnalytics.ConsentType.AD_STORAGE to denied,
            FirebaseAnalytics.ConsentType.AD_USER_DATA to denied,
            FirebaseAnalytics.ConsentType.AD_PERSONALIZATION to denied,
        ))
    }

    private fun parameters(values: Map<String, WireValue>): Bundle = Bundle().apply {
        values.filterKeys { it.startsWith(PARAMETER_PREFIX) }.forEach { (key, value) ->
            val name = key.removePrefix(PARAMETER_PREFIX)
            when (value) {
                is WireValue.Text -> putString(name, value.value)
                is WireValue.Integer -> putLong(name, value.value)
                is WireValue.Decimal -> putDouble(name, value.value)
                is WireValue.Flag -> putLong(name, if (value.value) 1 else 0)
            }
        }
    }

    private fun Map<String, WireValue>.text(key: String): String =
        (get(key) as? WireValue.Text)?.value ?: error("$key is required")

    private fun Map<String, WireValue>.flag(key: String): Boolean =
        (get(key) as? WireValue.Flag)?.value ?: error("$key is required")

    private fun ModuleCompletion.success(available: Boolean = true) {
        complete(ModuleResultStatus.SUCCESS, WireMap.encode(mapOf("available" to WireValue.Flag(available))))
    }

    private fun ModuleCompletion.failure(error: Throwable) {
        complete(
            ModuleResultStatus.FAILURE,
            (error.message ?: error::class.java.simpleName).toByteArray(Charsets.UTF_8),
        )
    }

    private companion object {
        const val PARAMETER_PREFIX = "param_"
    }
}
