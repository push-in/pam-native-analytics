import FirebaseAnalytics
import FirebaseCore
import Foundation
import PamNative

/// Firebase Analytics without advertising identifiers (FirebaseAnalyticsCore,
/// no IDFA). Firebase is configured by the host when the project ships
/// `GoogleService-Info.plist`; this module only falls back to a plist in the
/// main bundle. Without either, every call answers `available = false`.
public final class AnalyticsModule: NativeModule, @unchecked Sendable {
    private static let parameterPrefix = "param_"

    public init() {}

    public func invoke(method: String, payload: Data, completion: @escaping ModuleCompletion) {
        let values: [String: WireValue]
        do {
            values = try WireMap.decode(payload)
        } catch {
            failure(String(describing: error), completion: completion)
            return
        }
        // Firebase entry points expect the main thread for configure().
        DispatchQueue.main.async {
            guard Self.firebaseAvailable() else {
                self.success(available: false, completion: completion)
                return
            }
            do {
                switch method {
                case "setConsent": try self.setConsent(values)
                case "setCollectionEnabled": Analytics.setAnalyticsCollectionEnabled(try values.flag("enabled"))
                case "logEvent": Analytics.logEvent(try values.text("event"), parameters: self.parameters(values))
                case "setScreen":
                    Analytics.logEvent(AnalyticsEventScreenView, parameters: [
                        AnalyticsParameterScreenName: try values.text("screen"),
                        AnalyticsParameterScreenClass: try values.text("screenClass"),
                    ])
                default: throw AnalyticsBridgeError.unknownMethod(method)
                }
                self.success(available: true, completion: completion)
            } catch {
                self.failure(String(describing: error), completion: completion)
            }
        }
    }

    private static func firebaseAvailable() -> Bool {
        if FirebaseApp.app() != nil { return true }
        guard let path = Bundle.main.path(forResource: "GoogleService-Info", ofType: "plist"),
              let options = FirebaseOptions(contentsOfFile: path) else { return false }
        FirebaseApp.configure(options: options)
        return FirebaseApp.app() != nil
    }

    private func setConsent(_ values: [String: WireValue]) throws {
        // Advertising signals are denied whatever PHP sends.
        Analytics.setConsent([
            .analyticsStorage: try values.flag("analyticsStorage") ? .granted : .denied,
            .adStorage: .denied,
            .adUserData: .denied,
            .adPersonalization: .denied,
        ])
    }

    private func parameters(_ values: [String: WireValue]) -> [String: Any] {
        var parameters: [String: Any] = [:]
        for (key, value) in values where key.hasPrefix(Self.parameterPrefix) {
            let name = String(key.dropFirst(Self.parameterPrefix.count))
            switch value {
            case let .text(value): parameters[name] = value
            case let .integer(value): parameters[name] = value
            case let .decimal(value): parameters[name] = value
            case let .flag(value): parameters[name] = value ? 1 : 0
            }
        }
        return parameters
    }

    private func success(available: Bool, completion: ModuleCompletion) {
        do {
            completion(.success, try WireMap.encode(["available": .flag(available)]))
        } catch {
            failure(String(describing: error), completion: completion)
        }
    }

    private func failure(_ message: String, completion: ModuleCompletion) {
        completion(.failure, Data(message.utf8))
    }
}

private extension Dictionary where Key == String, Value == WireValue {
    func text(_ key: String) throws -> String {
        guard case let .text(value)? = self[key] else { throw AnalyticsBridgeError.missingValue(key) }
        return value
    }

    func flag(_ key: String) throws -> Bool {
        guard case let .flag(value)? = self[key] else { throw AnalyticsBridgeError.missingValue(key) }
        return value
    }
}

private enum AnalyticsBridgeError: Error {
    case missingValue(String)
    case unknownMethod(String)
}
