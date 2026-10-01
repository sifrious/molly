import Foundation

/// One process the plugin starts: `php artisan molly:… --json` or `git -C … diff`.
struct Invocation: Sendable, Hashable {
    var executable: String
    var arguments: [String]
    var cwd: String

    /// A line the person can paste into Terminal to run the same command.
    var display: String {
        let command = ([executable] + arguments).map(ShellQuote.quote).joined(separator: " ")
        return cwd.isEmpty ? command : "cd \(ShellQuote.quote(cwd)) && \(command)"
    }
}

enum ShellQuote {
    static func quote(_ value: String) -> String {
        let safe = CharacterSet(charactersIn: "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_./=:@,+%")
        if !value.isEmpty, value.unicodeScalars.allSatisfy(safe.contains) { return value }
        return "'" + value.replacingOccurrences(of: "'", with: "'\\''") + "'"
    }
}

/// Why a command did not produce usable data. `none` means the JSON payload is ready to read.
enum CommandFailure: Sendable, Equatable {
    case none
    /// No Laravel app with Molly was found to run Artisan in.
    case noHost
    /// No PHP binary could be started (M03.5 ENOENT).
    case phpMissing
    /// This Molly version does not define the command.
    case commandNotAvailable(String)
    /// The process could not be started at all.
    case launchFailed(String)
    /// Molly returned `status: error` or a top-level `error` string.
    case errorPayload(String)
    /// The process exited without a JSON payload.
    case notJSON
}

struct CommandOutcome: Sendable {
    var invocation: Invocation
    var exitCode: Int32
    var stdout: String
    var stderr: String
    var json: JSONValue?
    var failure: CommandFailure

    var succeeded: Bool { failure == .none }

    var headline: String {
        switch failure {
        case .none: return "Command finished"
        case .noHost: return "No Molly app to run Artisan in"
        case .phpMissing: return "PHP was not found"
        case .commandNotAvailable(let name): return "\(name) is not available in this Molly version"
        case .launchFailed: return "The command could not start"
        case .errorPayload: return "Molly reported an error"
        case .notJSON: return "The command did not return JSON"
        }
    }

    var detail: String {
        switch failure {
        case .none: return ""
        case .noHost:
            return "Pick a project on the Molly page, add an existing Laravel app with Molly installed, or set MOLLY_ARTISAN_HOST to a Laravel app root before launching Bloom."
        case .phpMissing:
            return "Molly looked for PHP in MOLLY_PHP_BINARY, \(PHPLocator.herdPath), /opt/homebrew/bin/php, /usr/local/bin/php, and then `/usr/bin/env php` on Bloom's PATH. Set MOLLY_PHP_BINARY to an absolute php path and relaunch Bloom."
        case .commandNotAvailable(let name):
            return "The Molly package installed in this app does not define \(name). Update sifrious/molly in the selected project to use this screen."
        case .launchFailed(let message): return message
        case .errorPayload(let message): return message
        case .notJSON: return "Exit code \(exitCode). Read stdout and stderr below."
        }
    }

    /// Classifies a finished process. Pure, so tests cover it without PHP.
    static func classify(command: String?, exitCode: Int32, stdout: String, stderr: String, usedEnvPHP: Bool) -> (JSONValue?, CommandFailure) {
        let combined = stdout + "\n" + stderr
        if let command, command.hasPrefix("molly:"),
           combined.contains("Command \"\(command)\" is not defined")
            || combined.contains("There are no commands defined in the \"\(command)\" namespace")
            || (combined.contains("is not defined") && combined.contains(command)) {
            return (nil, .commandNotAvailable(command))
        }
        if usedEnvPHP, exitCode == 127 {
            return (nil, .phpMissing)
        }
        let json = JSONValue.parse(stdout)
        guard let json else {
            return (nil, .notJSON)
        }
        if json["status"]?.string == "error" {
            let message = json["error"]?.string
                ?? json.at("report.error")?.string
                ?? json["message"]?.string
                ?? (stderr.isEmpty ? "Molly returned status error." : stderr)
            return (json, .errorPayload(message))
        }
        if let message = json["error"]?.string, !message.isEmpty {
            return (json, .errorPayload(message))
        }
        return (json, .none)
    }
}

/// Finds PHP when Bloom runs with the short PATH macOS gives GUI apps.
enum PHPLocator {
    static let herdPath = NSHomeDirectory() + "/Library/Application Support/Herd/bin/php"

    static func candidates(environment: [String: String]) -> [String] {
        var paths: [String] = []
        if let override = environment["MOLLY_PHP_BINARY"], !override.isEmpty { paths.append(override) }
        paths.append(herdPath)
        paths.append("/opt/homebrew/bin/php")
        paths.append("/usr/local/bin/php")
        return paths
    }

    /// Returns an absolute PHP path, or `nil` to fall back to `/usr/bin/env php`.
    static func resolve(environment: [String: String], isExecutable: (String) -> Bool) -> String? {
        candidates(environment: environment).first(where: isExecutable)
    }
}

/// Reads the project index Molly's `ProjectRegistry` writes: `~/.molly/projects.json` (a JSON
/// array of absolute paths) and `<path>/.molly/project.json` for each entry.
struct MollyProjectIndex: Sendable {
    struct Project: Sendable, Hashable, Identifiable {
        var id: String
        var name: String
        var path: String
        var source: String
        var createdAt: String
        var hasArtisan: Bool
    }

    var home: String

    init(environment: [String: String]) {
        if let override = environment["MOLLY_HOME"], !override.isEmpty {
            home = override
        } else {
            home = NSHomeDirectory() + "/.molly"
        }
    }

    var indexPath: String { home + "/projects.json" }

    func load(fileManager: FileManager = .default) -> (projects: [Project], problem: String?) {
        guard fileManager.fileExists(atPath: indexPath) else {
            return ([], nil)
        }
        guard let data = fileManager.contents(atPath: indexPath),
              let paths = try? JSONDecoder().decode([String].self, from: data) else {
            return ([], "\(indexPath) is not a JSON array of project paths.")
        }
        var projects: [Project] = []
        for path in paths {
            let recordPath = path + "/.molly/project.json"
            let hasArtisan = fileManager.isReadableFile(atPath: path + "/artisan")
            guard let recordData = fileManager.contents(atPath: recordPath),
                  let record = JSONValue.parse(String(decoding: recordData, as: UTF8.self)) else {
                projects.append(Project(id: path, name: (path as NSString).lastPathComponent, path: path, source: "missing .molly/project.json", createdAt: "", hasArtisan: hasArtisan))
                continue
            }
            projects.append(Project(
                id: record["id"]?.string ?? path,
                name: record["name"]?.string ?? (path as NSString).lastPathComponent,
                path: path,
                source: record["source"]?.string ?? "",
                createdAt: record["created_at"]?.string ?? "",
                hasArtisan: hasArtisan
            ))
        }
        return (projects, nil)
    }

    /// The Laravel app Artisan runs in: the selected project, then MOLLY_ARTISAN_HOST, then the
    /// first indexed project with an `artisan` file.
    static func resolveHost(selected: String?, environment: [String: String], projects: [Project], fileManager: FileManager = .default) -> String? {
        if let selected, fileManager.isReadableFile(atPath: selected + "/artisan") { return selected }
        if let override = environment["MOLLY_ARTISAN_HOST"], !override.isEmpty,
           fileManager.isReadableFile(atPath: override + "/artisan") {
            return override
        }
        return projects.first(where: \.hasArtisan)?.path
    }
}

/// Runs commands off the main thread and captures stdout and stderr in full.
struct MollyRunner: Sendable {
    var environment: [String: String] = ProcessInfo.processInfo.environment

    func artisanInvocation(_ arguments: [String], host: String) -> (Invocation, usedEnvPHP: Bool) {
        let php = PHPLocator.resolve(environment: environment) { FileManager.default.isExecutableFile(atPath: $0) }
        let args = ["artisan"] + arguments + ["--no-interaction"]
        if let php {
            return (Invocation(executable: php, arguments: args, cwd: host), false)
        }
        return (Invocation(executable: "/usr/bin/env", arguments: ["php"] + args, cwd: host), true)
    }

    /// Runs `php artisan <arguments>` in `host`. A `nil` host returns a `noHost` outcome
    /// without starting anything.
    func artisan(_ arguments: [String], host: String?) async -> CommandOutcome {
        let command = arguments.first
        guard let host else {
            let invocation = Invocation(executable: "php", arguments: ["artisan"] + arguments, cwd: "")
            return CommandOutcome(invocation: invocation, exitCode: -1, stdout: "", stderr: "", json: nil, failure: .noHost)
        }
        let (invocation, usedEnvPHP) = artisanInvocation(arguments, host: host)
        let raw = await run(invocation)
        if let launchError = raw.launchError {
            let failure: CommandFailure = usedEnvPHP || launchError.contains("No such file") ? .phpMissing : .launchFailed(launchError)
            return CommandOutcome(invocation: invocation, exitCode: raw.exitCode, stdout: raw.stdout, stderr: raw.stderr.isEmpty ? launchError : raw.stderr, json: nil, failure: failure)
        }
        let (json, failure) = CommandOutcome.classify(command: command, exitCode: raw.exitCode, stdout: raw.stdout, stderr: raw.stderr, usedEnvPHP: usedEnvPHP)
        return CommandOutcome(invocation: invocation, exitCode: raw.exitCode, stdout: raw.stdout, stderr: raw.stderr, json: json, failure: failure)
    }

    struct RawResult: Sendable {
        var exitCode: Int32
        var stdout: String
        var stderr: String
        var launchError: String?
    }

    func run(_ invocation: Invocation) async -> RawResult {
        let environment = childEnvironment(for: invocation.executable)
        return await withCheckedContinuation { continuation in
            DispatchQueue.global(qos: .userInitiated).async {
                continuation.resume(returning: Self.runBlocking(invocation, environment: environment))
            }
        }
    }

    /// GUI apps start with PATH=/usr/bin:/bin:/usr/sbin:/sbin. Artisan commands shell out to
    /// composer and git, so the child gets PHP's directory and the usual install roots first.
    func childEnvironment(for executable: String) -> [String: String] {
        var env = environment
        var prefix: [String] = []
        if executable.hasPrefix("/") && executable != "/usr/bin/env" {
            prefix.append((executable as NSString).deletingLastPathComponent)
        }
        prefix.append((PHPLocator.herdPath as NSString).deletingLastPathComponent)
        prefix.append(contentsOf: ["/opt/homebrew/bin", "/usr/local/bin"])
        env["PATH"] = (prefix + [env["PATH"] ?? "/usr/bin:/bin:/usr/sbin:/sbin"]).joined(separator: ":")
        env["NO_COLOR"] = "1"
        return env
    }

    private final class Buffer: @unchecked Sendable {
        var data = Data()
    }

    private static func runBlocking(_ invocation: Invocation, environment: [String: String]) -> RawResult {
        let process = Process()
        process.executableURL = URL(fileURLWithPath: invocation.executable)
        process.arguments = invocation.arguments
        if !invocation.cwd.isEmpty {
            process.currentDirectoryURL = URL(fileURLWithPath: invocation.cwd, isDirectory: true)
        }
        process.environment = environment
        process.standardInput = FileHandle.nullDevice
        let out = Pipe()
        let err = Pipe()
        process.standardOutput = out
        process.standardError = err

        do {
            try process.run()
        } catch {
            return RawResult(exitCode: -1, stdout: "", stderr: "", launchError: "\(invocation.executable): \(error.localizedDescription)")
        }

        // Read both pipes while the process runs so a large diff or report cannot fill a pipe
        // buffer and block the child.
        let stdout = Buffer()
        let stderr = Buffer()
        let group = DispatchGroup()
        group.enter()
        DispatchQueue.global().async {
            stdout.data = out.fileHandleForReading.readDataToEndOfFile()
            group.leave()
        }
        group.enter()
        DispatchQueue.global().async {
            stderr.data = err.fileHandleForReading.readDataToEndOfFile()
            group.leave()
        }
        process.waitUntilExit()
        group.wait()

        return RawResult(
            exitCode: process.terminationStatus,
            stdout: String(decoding: stdout.data, as: UTF8.self),
            stderr: String(decoding: stderr.data, as: UTF8.self),
            launchError: nil
        )
    }
}
