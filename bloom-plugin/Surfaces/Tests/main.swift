import Foundation

// Plain assertions so the core compiles and runs without XCTest or a Bloom checkout.
nonisolated(unsafe) var failures: [String] = []
nonisolated(unsafe) var passed = 0

func check(_ condition: @autoclosure () -> Bool, _ name: String) {
    if condition() {
        passed += 1
    } else {
        failures.append(name)
        print("FAIL \(name)")
    }
}

// JSON parsing
let noisy = "PHP Deprecated: something\n{\"status\":\"ok\",\"projects\":[{\"id\":\"p1\",\"approved\":true,\"count\":3}]}\n"
let parsed = JSONValue.parse(noisy)
check(parsed?["status"]?.string == "ok", "parse takes the JSON line after PHP warnings")
check(parsed?.at("projects")?.array?.first?["approved"]?.bool == true, "booleans stay booleans")
check(parsed?.at("projects")?.array?.first?["count"]?.int == 3, "integers read as Int")
check(JSONValue.parse("not json") == nil, "plain text is not JSON")
check(JSONValue.parse("{\"a\":null}")?["a"]?.isNull == true, "null is kept")

// Classification
func classify(_ command: String, exit: Int32, out: String, err: String = "", env: Bool = false) -> CommandFailure {
    CommandOutcome.classify(command: command, exitCode: exit, stdout: out, stderr: err, usedEnvPHP: env).1
}
check(classify("molly:tasks", exit: 0, out: "{\"tasks\":[]}") == .none, "tasks payload succeeds")
check(classify("molly:worker", exit: 1, out: "", err: "\n  Command \"molly:worker\" is not defined.\n") == .commandNotAvailable("molly:worker"), "missing command is not available, not an error")
check(classify("molly:glossary", exit: 1, out: "  There are no commands defined in the \"molly:glossary\" namespace.") == .commandNotAvailable("molly:glossary"), "missing namespace is not available")
check(classify("molly:tasks", exit: 127, out: "", err: "env: php: No such file or directory", env: true) == .phpMissing, "env php exit 127 means PHP is missing")
check(classify("molly:tasks", exit: 1, out: "{\"tasks\":[],\"error\":\"TASK_LIMIT_INVALID\"}") == .errorPayload("TASK_LIMIT_INVALID"), "top-level error string is an error")
check(classify("molly:start", exit: 1, out: "{\"id\":null,\"status\":\"error\",\"report\":{\"error\":\"TASK_NOT_FOUND\"}}") == .errorPayload("TASK_NOT_FOUND"), "status error reads report.error")
check(classify("molly:start", exit: 1, out: "{\"id\":\"r1\",\"status\":\"failed\",\"report\":{}}") == .none, "a failed run is data, not a command error")
check(classify("molly:tasks", exit: 1, out: "Illuminate\\Database\\QueryException") == .notJSON, "stack trace without JSON is notJSON")

// PHP lookup order
let herd = PHPLocator.herdPath
check(PHPLocator.resolve(environment: ["MOLLY_PHP_BINARY": "/custom/php"]) { $0 == "/custom/php" || $0 == herd } == "/custom/php", "MOLLY_PHP_BINARY wins")
check(PHPLocator.resolve(environment: [:]) { $0 == herd || $0 == "/opt/homebrew/bin/php" } == herd, "Herd before Homebrew")
check(PHPLocator.resolve(environment: [:]) { $0 == "/usr/local/bin/php" } == "/usr/local/bin/php", "falls through to /usr/local/bin")
check(PHPLocator.resolve(environment: [:]) { _ in false } == nil, "nil means /usr/bin/env php")

// Project index and host resolution
let temp = FileManager.default.temporaryDirectory.appendingPathComponent("molly-surfaces-tests-\(UUID().uuidString)")
let home = temp.appendingPathComponent("molly-home")
let appWithArtisan = temp.appendingPathComponent("app-one")
let appWithout = temp.appendingPathComponent("app-two")
try FileManager.default.createDirectory(at: home, withIntermediateDirectories: true)
for app in [appWithArtisan, appWithout] {
    try FileManager.default.createDirectory(at: app.appendingPathComponent(".molly"), withIntermediateDirectories: true)
    try "{\"id\":\"\(app.lastPathComponent)\",\"name\":\"\(app.lastPathComponent)\",\"source\":\"init\",\"created_at\":\"2026-09-27\"}"
        .write(to: app.appendingPathComponent(".molly/project.json"), atomically: true, encoding: .utf8)
}
try "#!/usr/bin/env php".write(to: appWithArtisan.appendingPathComponent("artisan"), atomically: true, encoding: .utf8)
try "[\"\(appWithout.path)\",\"\(appWithArtisan.path)\"]".write(to: home.appendingPathComponent("projects.json"), atomically: true, encoding: .utf8)

let index = MollyProjectIndex(environment: ["MOLLY_HOME": home.path])
let loaded = index.load()
check(loaded.projects.count == 2 && loaded.problem == nil, "index lists both projects")
check(loaded.projects.first(where: { $0.path == appWithArtisan.path })?.hasArtisan == true, "artisan file detected")
check(MollyProjectIndex.resolveHost(selected: nil, environment: [:], projects: loaded.projects) == appWithArtisan.path, "first project with artisan hosts commands")
check(MollyProjectIndex.resolveHost(selected: appWithout.path, environment: [:], projects: loaded.projects) == appWithArtisan.path, "a selection without artisan falls back")
check(MollyProjectIndex.resolveHost(selected: nil, environment: [:], projects: []) == nil, "no projects means no host")
check(MollyProjectIndex(environment: ["MOLLY_HOME": temp.appendingPathComponent("missing").path]).load().projects.isEmpty, "missing index is empty, not an error")
try? FileManager.default.removeItem(at: temp)

// Invocation display
let invocation = Invocation(executable: "/opt/example/Application Support/Herd/bin/php", arguments: ["artisan", "molly:create", "Fix the greeting", "--json"], cwd: "/tmp/app")
check(invocation.display == "cd /tmp/app && '/opt/example/Application Support/Herd/bin/php' artisan molly:create 'Fix the greeting' --json", "display quotes paths and prompts")

// Runner
let noHostOutcome = await MollyRunner().artisan(["molly:tasks", "--json"], host: nil)
let big = await MollyRunner().run(Invocation(executable: "/bin/sh", arguments: ["-c", "head -c 300000 /dev/zero | tr '\\0' a; echo err >&2"], cwd: ""))
check(noHostOutcome.failure == .noHost && noHostOutcome.exitCode == -1, "no host never starts a process")
check(big.stdout.count == 300_000 && big.stderr == "err\n", "large output does not block the pipe")

print("\(passed) passed, \(failures.count) failed")
exit(failures.isEmpty ? 0 : 1)
