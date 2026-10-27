# PAM Native Analytics

Privacy-safe product analytics for PAM Native: Firebase Analytics with no
advertising identifiers.

```php
use Pam\Native\Analytics\Analytics;

Analytics::setConsent();                 // analytics storage granted; ad_* always denied
Analytics::setCollectionEnabled(true);   // collection starts disabled
Analytics::logEvent('content_share', ['post_type' => 1, 'share_channel' => 2]);
Analytics::setScreen('Inbox');           // screen_view (screen_name = screen_class)
```

Every call is fire-and-forget. The optional completion receives
`AnalyticsOperationState`: `Succeeded`, `Failed` or `Unavailable`.

## Privacy

- Android drops `AD_ID`, `ACCESS_ADSERVICES_AD_ID` and
  `ACCESS_ADSERVICES_ATTRIBUTION`, and disables ADID/SSAID collection.
- iOS links `FirebaseAnalyticsCore` (no IDFA support) and disables IDFV
  collection and ad-network registration.
- Ad storage, ad user data and ad personalization are denied by default and
  stay denied: `setConsent()` cannot grant them.
- Automatic screen reporting is off. Screens come only from `setScreen()`.
- Event and parameter names follow the Analytics limits: up to 40 characters,
  at most 25 parameters, text values cut to 100 characters, and no
  `firebase_`, `google_` or `ga_` prefixes.

## Configuration

The plugin reuses the project's Firebase configuration:
`google-services.json` on Android and `GoogleService-Info.plist` on iOS, which
the host already uses for push. Without it every call is a silent no-op that
answers `Unavailable`.

## Tests

`composer test`. Without `vendor/`, point `PAM_NATIVE_SRC` and
`PAM_NATIVE_TESTING_SRC` at the framework and testing sources.
