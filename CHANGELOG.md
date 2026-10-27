# Changelog

## 0.1.0 - unreleased

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
- Pending: Android and iOS compilation in a real host build.
