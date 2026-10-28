# Changelog

## 0.1.0 - 2026-10-27

- `Analytics::setConsent()`, `setCollectionEnabled()`, `logEvent()` and
  `setScreen()` over Firebase Analytics on Android (Kotlin) and iOS (Swift,
  FirebaseAnalyticsCore through SPM).
- No advertising identifiers on either platform. Collection starts disabled.
  Without Firebase configuration every call is a silent no-op.
- Checked against firebase-ios-sdk 12.17.0 (`FirebaseAnalyticsCore` exists and
  links `GoogleAppMeasurementCore`, without IdentitySupport) and the Android
  `FirebaseAnalytics.setConsent(Map<ConsentType, ConsentStatus>)` API.
- Documented the IDFA conflict with `pam-native-firebase` (its
  `FirebaseAnalytics` product brings IDFA support back on iOS).
- Declares `WAKE_LOCK`, which `FirebaseAnalytics.getInstance` needs (Android lint
  `MissingPermission`).
- Compiled against the PAM Native host build: Kotlin with lint as errors, and
  Swift for the iOS Simulator with firebase-ios-sdk 12.17.0 (no IdentitySupport
  or AdSupport linked).
