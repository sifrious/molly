import Foundation

/// Shared reading of Molly's versioned JSON.
///
/// Molly's PHP contracts encode empty objects as `[]` when they have no keys, timestamps as
/// `YYYY-MM-DDTHH:MM:SSZ`, and UUIDs in lowercase. Bloom already treats untrusted JSON as
/// `JSONValue`; this is the same decoder with Molly's field rules on top, so a newer unknown
/// document stays inspectable instead of crashing the inspector.
enum MollyJSON {
    static func object(_ json: JSONValue, named field: String) throws -> [String: JSONValue] {
        if json.arrayValue?.isEmpty == true { return [:] }
        guard let object = json.objectValue else {
            throw MollyContractError("CONTRACT_FIELD_INVALID: \(field) must be an object.")
        }
        return object
    }

    static func requireSchema(_ json: JSONValue, _ schema: String) throws {
        guard json["schema"]?.stringValue == schema else {
            throw MollyContractError("CONTRACT_SCHEMA_INVALID: Expected \(schema).")
        }
    }

    static func string(_ json: JSONValue, _ field: String) throws -> String {
        guard let value = json[field]?.stringValue, !value.isEmpty else {
            throw MollyContractError("CONTRACT_FIELD_INVALID: \(field) must be a non-empty string.")
        }
        return value
    }

    static func optionalString(_ json: JSONValue, _ field: String) throws -> String? {
        guard let value = json[field] else { return nil }
        guard let text = value.stringValue, !text.isEmpty else {
            throw MollyContractError("CONTRACT_FIELD_INVALID: \(field) must be a non-empty string or null.")
        }
        return text
    }

    static func uuid(_ json: JSONValue, _ field: String) throws -> String {
        try uuid(try string(json, field), named: field)
    }

    static func optionalUuid(_ json: JSONValue, _ field: String) throws -> String? {
        guard let value = try optionalString(json, field) else { return nil }
        return try uuid(value, named: field)
    }

    static func uuid(_ value: String, named field: String) throws -> String {
        let lowered = value.lowercased()
        guard lowered.wholeMatch(of: uuidPattern) != nil else {
            throw MollyContractError("CONTRACT_FIELD_INVALID: \(field) must be a UUID.")
        }
        return lowered
    }

    static func integer(_ json: JSONValue, _ field: String, min: Int, max: Int) throws -> Int {
        guard let value = json[field]?.intValue else {
            throw MollyContractError("CONTRACT_FIELD_INVALID: \(field) must be an integer.")
        }
        guard (min...max).contains(value) else {
            throw MollyContractError("CONTRACT_FIELD_INVALID: \(field) must be between \(min) and \(max).")
        }
        return value
    }

    static func boolean(_ json: JSONValue, _ field: String) throws -> Bool {
        guard let value = json[field]?.boolValue else {
            throw MollyContractError("CONTRACT_FIELD_INVALID: \(field) must be a boolean.")
        }
        return value
    }

    static func stringList(_ json: JSONValue, _ field: String) throws -> [String] {
        guard let values = json[field]?.arrayValue else {
            throw MollyContractError("CONTRACT_FIELD_INVALID: \(field) must be a list of strings.")
        }
        return try values.enumerated().map { index, value in
            guard let text = value.stringValue, !text.isEmpty else {
                throw MollyContractError("CONTRACT_FIELD_INVALID: \(field)[\(index)] must be a non-empty string.")
            }
            return text
        }
    }

    static func objectList(_ json: JSONValue, _ field: String) throws -> [[String: JSONValue]] {
        guard let values = json[field]?.arrayValue else {
            throw MollyContractError("CONTRACT_FIELD_INVALID: \(field) must be a list of objects.")
        }
        return try values.enumerated().map { index, value in
            try object(value, named: "\(field)[\(index)]")
        }
    }

    static func relativePath(_ path: String, named field: String) throws -> String {
        guard path == path.trimmingCharacters(in: .whitespacesAndNewlines),
              !path.hasPrefix("/"),
              !path.contains("\\"),
              !path.contains("\0")
        else {
            throw MollyContractError("CONTRACT_FIELD_INVALID: \(field) must be a relative path.")
        }
        for segment in path.split(separator: "/", omittingEmptySubsequences: false) {
            if segment.isEmpty || segment == "." || segment == ".." {
                throw MollyContractError("CONTRACT_FIELD_INVALID: \(field) must be a relative path.")
            }
        }
        return path
    }

    static func sha1(_ value: String, named field: String) throws -> String {
        let lowered = value.lowercased()
        guard lowered.wholeMatch(of: sha1Pattern) != nil else {
            throw MollyContractError("CONTRACT_FIELD_INVALID: \(field) must be a 40-character Git SHA.")
        }
        return lowered
    }

    static func sha256(_ value: String, named field: String) throws -> String {
        let lowered = value.lowercased()
        guard lowered.wholeMatch(of: sha256Pattern) != nil else {
            throw MollyContractError("CONTRACT_FIELD_INVALID: \(field) must be a SHA-256 hex digest.")
        }
        return lowered
    }

    static func time(_ json: JSONValue, _ field: String) throws -> Date {
        try parseTime(try string(json, field), named: field)
    }

    static func parseTime(_ value: String, named field: String) throws -> Date {
        if let date = iso8601.date(from: value) { return date }
        throw MollyContractError("CONTRACT_FIELD_INVALID: \(field) must be an ISO-8601 timestamp.")
    }

    static func formatTime(_ date: Date) -> String {
        iso8601.string(from: date)
    }

    static func encode(_ value: JSONValue) throws -> String {
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.withoutEscapingSlashes]
        let data = try encoder.encode(value)
        return String(decoding: data, as: UTF8.self)
    }

    private static let uuidPattern = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/
    private static let sha1Pattern = /[0-9a-f]{40}/
    private static let sha256Pattern = /[0-9a-f]{64}/

    private static let iso8601: ISO8601DateFormatter = {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime]
        formatter.timeZone = TimeZone(secondsFromGMT: 0)
        return formatter
    }()
}
