# Changelog

## 0.1.0 - unreleased

- `Analytics::setConsent()`, `setCollectionEnabled()`, `logEvent()` and
  `setScreen()` over Firebase Analytics on Android (Kotlin) and iOS (Swift,
  FirebaseAnalyticsCore through SPM).
- No advertising identifiers on either platform. Collection starts disabled.
  Without Firebase configuration every call is a silent no-op.
- Pending: Android and iOS compilation in a real host build.
