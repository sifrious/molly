import Foundation

/// JSON read from a Molly `--json` command.
///
/// The plugin reads fields defensively: a missing key returns `nil` and the view shows
/// "Not reported" instead of a guessed value.
enum JSONValue: Sendable, Hashable {
    case string(String)
    case number(Double)
    case bool(Bool)
    case null
    case array([JSONValue])
    case object([String: JSONValue])

    init(any value: Any) {
        switch value {
        case let string as String:
            self = .string(string)
        case let number as NSNumber:
            if CFGetTypeID(number) == CFBooleanGetTypeID() {
                self = .bool(number.boolValue)
            } else {
                self = .number(number.doubleValue)
            }
        case let array as [Any]:
            self = .array(array.map(JSONValue.init(any:)))
        case let object as [String: Any]:
            self = .object(object.mapValues(JSONValue.init(any:)))
        default:
            self = .null
        }
    }

    /// Parses a whole payload, or the last line that holds a JSON object when PHP printed
    /// warnings before the payload.
    static func parse(_ text: String) -> JSONValue? {
        let trimmed = text.trimmingCharacters(in: .whitespacesAndNewlines)
        if let value = parseObject(trimmed) { return value }
        for line in trimmed.split(separator: "\n").reversed() {
            if let value = parseObject(String(line)) { return value }
        }
        return nil
    }

    private static func parseObject(_ text: String) -> JSONValue? {
        guard let data = text.data(using: .utf8),
              let object = try? JSONSerialization.jsonObject(with: data, options: [.fragmentsAllowed]),
              object is [String: Any] || object is [Any]
        else { return nil }
        return JSONValue(any: object)
    }

    subscript(key: String) -> JSONValue? {
        if case .object(let object) = self { return object[key] }
        return nil
    }

    /// Follows a dotted path such as `report.verification.status`.
    func at(_ path: String) -> JSONValue? {
        path.split(separator: ".").reduce(Optional(self)) { value, key in value?[String(key)] }
    }

    var string: String? {
        switch self {
        case .string(let value): return value
        case .number(let value):
            return value.rounded() == value && abs(value) < 1e15 ? String(Int64(value)) : String(value)
        case .bool(let value): return value ? "true" : "false"
        default: return nil
        }
    }

    var int: Int? {
        if case .number(let value) = self { return Int(value) }
        if case .string(let value) = self { return Int(value) }
        return nil
    }

    var bool: Bool? {
        if case .bool(let value) = self { return value }
        return nil
    }

    var array: [JSONValue]? {
        if case .array(let value) = self { return value }
        return nil
    }

    var object: [String: JSONValue]? {
        if case .object(let value) = self { return value }
        return nil
    }

    var isNull: Bool {
        if case .null = self { return true }
        return false
    }

    var isScalar: Bool {
        switch self {
        case .array, .object: return false
        default: return true
        }
    }

    /// Text shown for a scalar. Null reads as "null" so a missing value is never shown as empty.
    var display: String {
        switch self {
        case .null: return "null"
        case .string(let value): return value
        case .array, .object: return pretty
        default: return string ?? ""
        }
    }

    var foundation: Any {
        switch self {
        case .string(let value): return value
        case .number(let value): return value.rounded() == value && abs(value) < 1e15 ? Int64(value) as Any : value
        case .bool(let value): return value
        case .null: return NSNull()
        case .array(let values): return values.map(\.foundation)
        case .object(let object): return object.mapValues(\.foundation)
        }
    }

    var pretty: String {
        let options: JSONSerialization.WritingOptions = [.prettyPrinted, .sortedKeys, .withoutEscapingSlashes, .fragmentsAllowed]
        guard let data = try? JSONSerialization.data(withJSONObject: foundation, options: options) else { return "" }
        return String(data: data, encoding: .utf8) ?? ""
    }

    var compact: String {
        let options: JSONSerialization.WritingOptions = [.sortedKeys, .withoutEscapingSlashes, .fragmentsAllowed]
        guard let data = try? JSONSerialization.data(withJSONObject: foundation, options: options) else { return "" }
        return String(data: data, encoding: .utf8) ?? ""
    }
}
