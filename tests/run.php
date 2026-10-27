<?php

declare(strict_types=1);

$vendor = dirname(__DIR__).'/vendor/autoload.php';
if (is_file($vendor)) {
    require $vendor;
}

// Without vendor/: PAM_NATIVE_SRC and PAM_NATIVE_TESTING_SRC, or the ecosystem layout.
$ecosystemRoot = dirname(__DIR__, 2);
$roots = [
    'Pam\\Native\\Analytics\\' => dirname(__DIR__).'/src/',
    'Pam\\Native\\Testing\\' => getenv('PAM_NATIVE_TESTING_SRC') ?: $ecosystemRoot.'/pam-native-testing/src/',
    'Pam\\Native\\' => getenv('PAM_NATIVE_SRC') ?: $ecosystemRoot.'/pam-native/packages/native/src/',
];
spl_autoload_register(static function (string $class) use ($roots): void {
    foreach ($roots as $prefix => $root) {
        if (str_starts_with($class, $prefix)) {
            $path = rtrim($root, '/').'/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($path)) { require $path; }
            return;
        }
    }
});

use Pam\Native\Analytics\Analytics;
use Pam\Native\Analytics\AnalyticsOperationState;
use Pam\Native\Internal\Wire;
use Pam\Native\Testing\NativeTestHarness;

$tests = [];
$test = static function (string $name, Closure $callback) use (&$tests): void { $tests[$name] = $callback; };
$expect = static function (bool $condition, string $message = 'Expectation failed'): void {
    if (!$condition) { throw new RuntimeException($message); }
};
$throws = static function (Closure $callback): bool {
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return true;
    }
    return false;
};

$test('consent always denies advertising signals', static function () use ($expect): void {
    $fake = NativeTestHarness::install();
    $fake->succeed('analytics', 'setConsent');
    $state = null;
    Analytics::setConsent(true, static function (AnalyticsOperationState $value) use (&$state): void { $state = $value; });
    $fake->flush();
    $payload = Wire::decodeMap($fake->lastCall()?->payload ?? '');
    $expect($payload == ['analyticsStorage' => true, 'adStorage' => false, 'adUserData' => false, 'adPersonalization' => false], 'consent payload');
    $expect($state === AnalyticsOperationState::Succeeded, 'consent state');
    $fake->assertSatisfied();
    NativeTestHarness::uninstall();
});

$test('collection toggle is a typed flag', static function () use ($expect): void {
    $fake = NativeTestHarness::install();
    $fake->succeed('analytics', 'setCollectionEnabled');
    Analytics::setCollectionEnabled(false);
    $fake->flush();
    $expect(Wire::decodeMap($fake->lastCall()?->payload ?? '') === ['enabled' => false]);
    $fake->assertSatisfied();
    NativeTestHarness::uninstall();
});

$test('events carry prefixed typed parameters and bounded text', static function () use ($expect): void {
    $fake = NativeTestHarness::install();
    $fake->succeed('analytics', 'logEvent');
    Analytics::logEvent('content_share', ['post_type' => 1, 'share_channel' => 2, 'ratio' => 0.5, 'first' => true, 'method' => str_repeat('a', 140)]);
    $fake->flush();
    $payload = Wire::decodeMap($fake->lastCall()?->payload ?? '');
    $expect($payload['event'] === 'content_share' && $payload['param_post_type'] === 1 && $payload['param_share_channel'] === 2, 'event payload');
    $expect($payload['param_ratio'] === 0.5 && $payload['param_first'] === true, 'typed values');
    $expect(strlen((string) $payload['param_method']) === Analytics::MAX_TEXT_LENGTH, 'text bounded');
    $fake->assertSatisfied();
    NativeTestHarness::uninstall();
});

$test('rejects names outside the Analytics limits and reserved prefixes', static function () use ($expect, $throws): void {
    $fake = NativeTestHarness::install();
    $expect($throws(static fn () => Analytics::logEvent('1bad')), 'leading digit');
    $expect($throws(static fn () => Analytics::logEvent(str_repeat('a', 41))), 'too long');
    $expect($throws(static fn () => Analytics::logEvent('firebase_x')), 'reserved event');
    $expect($throws(static fn () => Analytics::logEvent('ok', ['ga_x' => 1])), 'reserved parameter');
    $expect($throws(static fn () => Analytics::logEvent('ok', ['bad-key' => 1])), 'invalid parameter');
    $expect($throws(static fn () => Analytics::logEvent('ok', ['nan' => NAN])), 'non-finite');
    $expect($throws(static fn () => Analytics::logEvent('ok', array_fill_keys(array_map(static fn (int $i): string => 'p'.$i, range(1, 26)), 1))), 'too many');
    $expect($throws(static fn () => Analytics::setScreen('Bad Screen')), 'screen name');
    $expect($fake->calls() === [], 'nothing reached native');
    NativeTestHarness::uninstall();
});

$test('screen view defaults the class to the name', static function () use ($expect): void {
    $fake = NativeTestHarness::install();
    $fake->succeed('analytics', 'setScreen');
    Analytics::setScreen('Inbox');
    $fake->flush();
    $expect(Wire::decodeMap($fake->lastCall()?->payload ?? '') === ['screen' => 'Inbox', 'screenClass' => 'Inbox']);
    $fake->assertSatisfied();
    NativeTestHarness::uninstall();
});

$test('a build without Firebase reports Unavailable, not a failure', static function () use ($expect): void {
    $fake = NativeTestHarness::install();
    $fake->succeed('analytics', 'logEvent', ['available' => false]);
    $state = null;
    Analytics::logEvent('message_sent', ['message_type' => 1], static function (AnalyticsOperationState $value) use (&$state): void { $state = $value; });
    $fake->flush();
    $expect($state === AnalyticsOperationState::Unavailable);
    NativeTestHarness::uninstall();
});

$test('native failures reach the completion', static function () use ($expect): void {
    $fake = NativeTestHarness::install();
    $fake->fail('analytics', 'logEvent', 'boom');
    $state = null;
    $message = null;
    Analytics::logEvent('login', ['method' => 'app'], static function (AnalyticsOperationState $value, ?string $text) use (&$state, &$message): void { $state = $value; $message = $text; });
    $fake->flush();
    $expect($state === AnalyticsOperationState::Failed && $message === 'boom');
    NativeTestHarness::uninstall();
});

$test('manifest keeps advertising identifiers off on both platforms', static function () use ($expect): void {
    $root = dirname(__DIR__);
    $android = (string) file_get_contents($root.'/android/src/main/AndroidManifest.xml');
    foreach (['com.google.android.gms.permission.AD_ID', 'android.permission.ACCESS_ADSERVICES_AD_ID', 'android.permission.ACCESS_ADSERVICES_ATTRIBUTION'] as $permission) {
        $expect(preg_match('#'.preg_quote($permission, '#').'"\s+tools:node="remove"#', $android) === 1, "removes {$permission}");
    }
    foreach (['google_analytics_adid_collection_enabled', 'google_analytics_ssaid_collection_enabled', 'google_analytics_default_allow_ad_storage', 'google_analytics_default_allow_ad_user_data', 'google_analytics_default_allow_ad_personalization_signals', 'google_analytics_automatic_screen_reporting_enabled', 'firebase_analytics_collection_enabled'] as $key) {
        $expect(preg_match('#"'.$key.'"\s+android:value="false"#', $android) === 1, "android {$key}=false");
    }
    $plist = (string) file_get_contents($root.'/ios/Info.plist');
    foreach (['GOOGLE_ANALYTICS_IDFV_COLLECTION_ENABLED', 'GOOGLE_ANALYTICS_REGISTRATION_WITH_AD_NETWORK_ENABLED', 'GOOGLE_ANALYTICS_DEFAULT_ALLOW_AD_STORAGE', 'GOOGLE_ANALYTICS_DEFAULT_ALLOW_AD_USER_DATA', 'GOOGLE_ANALYTICS_DEFAULT_ALLOW_AD_PERSONALIZATION_SIGNALS', 'FirebaseAutomaticScreenReportingEnabled', 'FIREBASE_ANALYTICS_COLLECTION_ENABLED'] as $key) {
        $expect(preg_match('#<key>'.$key.'</key>\s*<false/>#', $plist) === 1, "ios {$key}=false");
    }
    $plugin = json_decode((string) file_get_contents($root.'/pam-native.plugin.json'), true, flags: JSON_THROW_ON_ERROR);
    $expect(!in_array('FirebaseAnalytics', $plugin['ios']['swiftPackages'][0]['products'], true), 'iOS links the analytics core without IDFA support');
});

$failures = 0;
foreach ($tests as $name => $callback) {
    try {
        $callback();
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $throwable) {
        ++$failures;
        NativeTestHarness::uninstall();
        fwrite(STDERR, "FAIL {$name}: {$throwable->getMessage()}\n");
    }
}
fwrite(STDOUT, sprintf("%d tests, %d failures\n", count($tests), $failures));
exit($failures === 0 ? 0 : 1);
