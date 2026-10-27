<?php

declare(strict_types=1);

namespace Pam\Native\Analytics;

use Closure;
use InvalidArgumentException;
use Pam\Native\Modules\NativeModuleResult;
use Pam\Native\Modules\NativeModules;

/**
 * Product analytics without advertising identifiers.
 *
 * Every call is fire-and-forget: the optional completion receives the state,
 * and a build without Firebase configuration answers
 * {@see AnalyticsOperationState::Unavailable} instead of failing. Collection
 * starts disabled (manifest/Info.plist) until {@see setCollectionEnabled()}.
 */
final class Analytics
{
    public const string MODULE = 'analytics';
    public const int MAX_PARAMETERS = 25;
    public const int MAX_TEXT_LENGTH = 100;

    private const string EVENT_NAME = '/^[A-Za-z][A-Za-z0-9_]{0,39}$/D';
    private const string PARAMETER_NAME = '/^[A-Za-z][A-Za-z0-9_]{0,39}$/D';
    private const string SCREEN_NAME = '/^[A-Za-z][A-Za-z0-9_]{0,99}$/D';
    private const array RESERVED_PREFIXES = ['firebase_', 'google_', 'ga_'];

    private function __construct()
    {
    }

    /**
     * Consent mode. Advertising storage, ad user data and ad personalization
     * are always denied; only analytics storage can be granted.
     *
     * @param (Closure(AnalyticsOperationState, ?string): void)|null $complete
     */
    public static function setConsent(bool $analyticsStorage = true, ?Closure $complete = null): int
    {
        return self::call('setConsent', [
            'analyticsStorage' => $analyticsStorage,
            'adStorage' => false,
            'adUserData' => false,
            'adPersonalization' => false,
        ], $complete);
    }

    /** @param (Closure(AnalyticsOperationState, ?string): void)|null $complete */
    public static function setCollectionEnabled(bool $enabled, ?Closure $complete = null): int
    {
        return self::call('setCollectionEnabled', ['enabled' => $enabled], $complete);
    }

    /**
     * @param array<string, bool|int|float|string> $parameters
     * @param (Closure(AnalyticsOperationState, ?string): void)|null $complete
     */
    public static function logEvent(string $name, array $parameters = [], ?Closure $complete = null): int
    {
        if (!self::validName($name, self::EVENT_NAME)) {
            throw new InvalidArgumentException("Invalid analytics event name {$name}.");
        }
        if (count($parameters) > self::MAX_PARAMETERS) {
            throw new InvalidArgumentException('Analytics events accept at most '.self::MAX_PARAMETERS.' parameters.');
        }
        $payload = ['event' => $name];
        foreach ($parameters as $key => $value) {
            if (!is_string($key) || !self::validName($key, self::PARAMETER_NAME)) {
                throw new InvalidArgumentException('Invalid analytics parameter '.$key.'.');
            }
            if (is_float($value) && !is_finite($value)) {
                throw new InvalidArgumentException("Analytics parameter {$key} must be finite.");
            }
            $payload['param_'.$key] = is_string($value) ? mb_substr($value, 0, self::MAX_TEXT_LENGTH) : $value;
        }

        return self::call('logEvent', $payload, $complete);
    }

    /**
     * Screen view (`screen_view` with `screen_name` and `screen_class`).
     *
     * @param (Closure(AnalyticsOperationState, ?string): void)|null $complete
     */
    public static function setScreen(string $name, ?string $class = null, ?Closure $complete = null): int
    {
        $class ??= $name;
        if (preg_match(self::SCREEN_NAME, $name) !== 1 || preg_match(self::SCREEN_NAME, $class) !== 1) {
            throw new InvalidArgumentException("Invalid analytics screen name {$name}.");
        }

        return self::call('setScreen', ['screen' => $name, 'screenClass' => $class], $complete);
    }

    private static function validName(string $name, string $pattern): bool
    {
        if (preg_match($pattern, $name) !== 1) {
            return false;
        }
        foreach (self::RESERVED_PREFIXES as $prefix) {
            if (str_starts_with(strtolower($name), $prefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, bool|int|float|string> $payload
     * @param (Closure(AnalyticsOperationState, ?string): void)|null $complete
     */
    private static function call(string $method, array $payload, ?Closure $complete): int
    {
        return NativeModules::call(self::MODULE, $method, $payload, static function (NativeModuleResult $result) use ($complete): void {
            if ($complete === null) {
                return;
            }
            if (!$result->succeeded()) {
                $complete(AnalyticsOperationState::Failed, $result->message());

                return;
            }
            $available = $result->values()['available'] ?? true;
            $complete($available === false ? AnalyticsOperationState::Unavailable : AnalyticsOperationState::Succeeded, null);
        });
    }
}
