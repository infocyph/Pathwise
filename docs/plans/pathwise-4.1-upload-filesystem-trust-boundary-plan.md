# Pathwise 4.1 — Upload & Filesystem Trust-Boundary Hardening Plan

**Status:** implementation plan  
**Target:** Pathwise 4.1  
**Branch:** `pathwise-4.1/foundation-runtime-security`  
**Primary consumer:** `infocyph/Foundation` runtime-security work  
**Priority:** containment/correctness → upload non-executability intent → race/symlink hardening → bounded resource use → performance → ergonomics

> Pathwise owns filesystem and upload trust boundaries. It must make attacker-controlled files safe to **store, inspect, scan, move, extract and serve as data**. It must not claim that uploaded PHP/source code is safe to execute, and it must not become a process/shell sandbox.

---

## 1. Baseline

Current Pathwise baseline already provides a strong starting point:

- PHP `>=8.4`;
- `ext-fileinfo` required;
- Flysystem-backed local/remote storage support;
- optional `ext-posix` for ownership metadata/helper behavior;
- deny-by-default `Security\PolicyEngine` support;
- hardened upload pipeline;
- authoritative actual-size validation;
- extension allow/block policies;
- MIME allowlisting and extension-to-MIME consistency checks;
- magic/signature validation;
- image/format-specific parsing;
- private malware scan materialization;
- `ClamAvDaemonScanner` using the clamd protocol directly rather than shell execution;
- resumable-upload scanning after final assembly;
- archive extraction validation and path-traversal protections.

The current malware-scan staging model already uses a private `0700` directory, `0600` scan file, before/after mutation checks, symlink-conversion detection, and `finally` cleanup. This pass should reuse that security philosophy for the wider upload/publication boundary rather than creating parallel mechanisms.

---

## 2. Why this pass exists

Foundation will deliberately deploy runtime process capabilities such as `pcntl` and `posix`, while trusted infrastructure may also require process execution APIs.

That does **not** make uploaded data dangerous by itself. The dangerous transition is:

```text
untrusted bytes
    -> executable/include path
    -> PHP/shell/process interpreter
```

Pathwise's responsibility is to make the first side of that boundary explicit and hard to cross accidentally:

```text
untrusted upload
    -> bounded private staging
    -> validation/scanning
    -> controlled publication root
    -> data-only artifact
```

Pathwise cannot prevent application code from later doing `include $uploadedPath`, `php $uploadedPath`, or passing the path to a shell. That execution policy belongs above Pathwise.

---

## 3. Ownership invariants

### Pathwise owns

- path normalization/canonicalization;
- configured root containment;
- local-path symlink/reparse-point checks where support exists;
- upload provenance and materialization;
- private staging/quarantine directories;
- safe final naming/publication;
- extension/MIME/signature/image/archive validation;
- malware-scanner contracts and bounded scan input;
- file/directory permission policy for Pathwise-managed untrusted artifacts;
- archive-entry path safety and extraction bounds;
- storage-operation/path authorization primitives;
- filesystem-level diagnostics/auditing that do not leak sensitive paths unnecessarily.

### ReqShield owns

- validation of request metadata, IDs, operation names and ordinary scalar/structured inputs;
- not filesystem containment or uploaded-byte execution safety.

### Foundation owns

- whether a route/feature accepts uploads;
- which Pathwise upload policy/profile is selected;
- application storage-root configuration;
- whether uploaded files are publicly addressable;
- web-server/PHP-FPM routing rules;
- authorization to use a stored artifact;
- whether any stored file may ever be passed to an interpreter/process;
- process/runtime capability selection;
- HTTP/application error mapping.

### Future process-runtime library owns

- safe process creation;
- argv-only execution policy;
- executable allowlisting;
- env/cwd policy;
- process groups/signals/timeouts;
- UID/GID/session/rlimit/sandbox integration.

### OS/deployment owns

- mount flags such as `noexec,nodev,nosuid`;
- service-account isolation;
- AppArmor/SELinux/seccomp/container policy;
- web-server executable-handler mappings;
- filesystem ownership outside Pathwise-managed operations.

---

## 4. Hard non-execution boundary

Pathwise must explicitly document and test this invariant:

> **A successful upload result means the artifact satisfied the configured storage/content policy. It never means the artifact is trusted executable code.**

Consequences:

- [ ] Do not scan source text for names such as `exec`, `system`, `shell_exec`, `proc_open`, `pcntl_*`, or `posix_*` as the primary security control.
- [ ] Do not reject harmless text merely because those tokens occur inside documents/source/data.
- [ ] Do not add PHP parser/evaluator behavior to determine whether uploaded PHP is "safe".
- [ ] Do not expose any `execute()`, `include()`, `eval()`, shell-command or process-launch API from Pathwise.
- [ ] Built-in malware scanners must not require spawning shell commands from request workers.
- [ ] Do not claim `noexec`, extension filtering or malware scanning alone makes a file safe to interpret.

The security objective is to keep uploaded content in the **data domain** unless an upper layer deliberately moves it into a separately controlled execution system.

---

## 5. Add an explicit untrusted-data upload policy/profile

Introduce a focused upload policy/profile for framework/runtime use. Naming can be adjusted to fit existing API style; preferred direction:

```text
UploadSecurityProfile::UNTRUSTED_DATA
```

or an immutable equivalent such as:

```text
UploadPublicationPolicy
```

Do not build a generic policy DSL. Reuse the current `UploadProcessor` setters/validation stages where possible.

### Strict profile requirements

When the strict untrusted-data profile is active:

- [ ] final destination root must be configured by trusted application code;
- [ ] client filenames cannot select directories;
- [ ] client path separators/drives/UNC/schemes cannot influence final location;
- [ ] final filename is generated/normalized server-side;
- [ ] a non-empty extension/content allowlist is preferred/required by policy rather than relying only on a blacklist;
- [ ] MIME allowlist is required for categories where reliable MIME checks exist;
- [ ] extension-to-MIME/signature compatibility remains enforced;
- [ ] malware scanning can be required independently through the existing `MalwareScanMode::REQUIRED`;
- [ ] local staged files are non-executable by Pathwise-managed permissions;
- [ ] special mode bits are never introduced on uploaded artifacts;
- [ ] publication refuses symlink/reparse targets where Pathwise can establish that condition safely;
- [ ] final publication happens only after every configured validation stage succeeds.

The existing flexible upload mode remains available for trusted/library callers. Foundation should opt into the strict untrusted-data policy for user uploads.

---

## 6. Path/root containment hardening

### 6.1 Canonical configured roots

Add/reuse a single canonical containment primitive rather than scattering string-prefix checks.

Required properties:

- [ ] normalize separators and redundant components;
- [ ] reject traversal that escapes the configured root;
- [ ] Windows comparisons handle drive-letter/case behavior correctly;
- [ ] reject unexpected URI schemes for direct-local operations;
- [ ] distinguish logical Flysystem paths from direct OS paths;
- [ ] do not use naive `str_starts_with($path, $root)` as a containment proof;
- [ ] root `/a/b` must not authorize `/a/b-evil`;
- [ ] containment behavior for a non-existing final target must be deterministic and tested.

Prefer one low-complexity value/service around these checks rather than multiple public abstractions.

### 6.2 Existing `PolicyEngine`

Audit `Security\PolicyEngine` path matching against containment-sensitive usage.

Its glob/pattern policy is suitable for authorization policy, but it must not be treated as canonical-filesystem containment by itself.

- [ ] Document that policy matching and OS containment are separate checks.
- [ ] Security-sensitive local operations should first establish the trusted root/canonical path relationship, then evaluate policy.
- [ ] Preserve deny-by-default behavior.
- [ ] Avoid application-controlled callback/context data becoming a bypass of root containment.

---

## 7. Symlink, link and TOCTOU hardening

### 7.1 Untrusted local input

For direct-local sources that can be attacker influenced:

- [ ] reject symbolic links when a regular file is required;
- [ ] use `lstat()`-style metadata checks where appropriate instead of following links accidentally;
- [ ] verify regular-file type before materialization;
- [ ] re-check critical metadata after scanner/parser handoff where mutation is possible;
- [ ] materialize attacker-controlled sources into a Pathwise-owned private file before deep inspection when trust cannot be established;
- [ ] never follow user-selected symlink chains into trusted roots during upload publication.

### 7.2 Windows

Audit junction/reparse-point behavior separately from POSIX symlinks.

- [ ] Add Windows tests where CI support is available.
- [ ] Do not document Unix symlink checks as universal protection if Windows reparse semantics differ.
- [ ] Fail closed in strict local-security paths when link/reparse state cannot be established reliably.

### 7.3 Hard links

Document the residual hard-link concern for attacker-writable local trees.

For untrusted uploads, prefer copy/materialization into a Pathwise-owned private inode rather than trusting an arbitrary pre-existing filesystem inode.

### 7.4 TOCTOU boundary

PHP userland path checks cannot provide a universal race-free replacement for OS `openat`/`O_NOFOLLOW`-style primitives on every platform.

Therefore:

- [ ] reduce the race window through private Pathwise-owned directories;
- [ ] revalidate around critical transitions;
- [ ] avoid claiming absolute race immunity;
- [ ] document that hostile users with direct write access to the same staging tree require OS-level isolation/permissions as the final boundary.

---

## 8. Staging, quarantine and publication lifecycle

Generalize the current malware scan-copy discipline into an explicit upload lifecycle:

```text
source
  -> private materialization/staging
  -> bounded metadata/content validation
  -> malware scan
  -> deeper MIME/signature/format parsing
  -> final name allocation
  -> publication
```

Requirements:

- [ ] private local staging directory defaults to `0700` where POSIX modes apply;
- [ ] untrusted staged files default to `0600`;
- [ ] cleanup is guaranteed through `finally` paths;
- [ ] failure at any stage does not leave a publicly addressable partially validated artifact;
- [ ] local same-filesystem publication should use atomic rename where feasible;
- [ ] cross-filesystem/remote adapters should use a non-public/non-final staging object/key and publish only after successful completion;
- [ ] avoid overwrite races when generating final names;
- [ ] preserve deterministic rollback/cleanup semantics when publication fails.

Do not create an elaborate transaction framework if current Pathwise transaction/journal primitives already cover the required lifecycle.

---

## 9. Permission-mode hardening for untrusted artifacts

Pathwise already has trusted general-purpose permission/ownership helpers. Keep those general APIs separate from upload security policy.

For Pathwise-managed **untrusted upload/staging** files specifically:

- [ ] do not copy executable bits from source metadata;
- [ ] do not honor client-provided file modes;
- [ ] do not set setuid/setgid/sticky bits on regular uploaded files;
- [ ] strict local publication mode should be non-executable (for example `0600`/`0640` according to configured visibility needs);
- [ ] private directories should remain non-world-writable unless explicitly configured by trusted code;
- [ ] permission failures in a strict profile fail closed rather than silently publishing broader permissions.

Do not globally cripple `PermissionsHelper::setPermissions()` for trusted filesystem-management use cases. Instead, ensure the upload pipeline never feeds untrusted permission values into that general API.

---

## 10. ext-posix boundary

Pathwise currently uses optional `ext-posix` for ownership identity lookup helpers such as `posix_getpwuid()`, `posix_getgrgid()` and effective-user checks.

Keep the extension boundary narrow:

- [ ] `ext-posix` remains optional for ownership/identity metadata features.
- [ ] Do not add `posix_kill`, `posix_setsid`, process-group manipulation, privilege dropping or process lifecycle management to Pathwise.
- [ ] Do not use `posix_setuid()` / `posix_setgid()` as an upload-security mechanism.
- [ ] Process/session/privilege APIs belong to the future process-runtime library/Foundation supervisor.
- [ ] Review `PermissionsHelper` documentation so installing `ext-posix` is not described as granting Pathwise process-control responsibilities.

This prevents Pathwise from colliding with the planned low-level process library or Omnibus worker orchestration.

---

## 11. Malware scanner boundary

Preserve the existing typed scanner architecture.

### Built-in scanner rules

- [ ] `ClamAvDaemonScanner` continues to use the clamd protocol directly.
- [ ] No built-in scanner may invoke `exec`, `system`, `shell_exec`, `passthru`, `popen`, `proc_open`, or `pcntl_exec` merely to launch scanner binaries.
- [ ] No sudo/root shell integration in request-worker code.
- [ ] Scanner exceptions remain wrapped behind stable public upload errors while preserving the original throwable internally.
- [ ] scanner input remains the Pathwise-owned materialized file, not an arbitrary application path.

### External scanners

If a deployment needs an executable-based scanner:

```text
Pathwise MalwareScannerInterface
        -> application adapter
        -> process-runtime/sandbox service
```

Pathwise may document this integration shape but must not depend on the process library just to support uploads.

---

## 12. Archive extraction hardening

Audit `ZipArchiveExtractor` / `ZipEntryValidator` against the same data-only boundary.

Required checks:

- [ ] traversal/absolute-path/drive/UNC escapes;
- [ ] symlink-like archive entries and metadata where exposed;
- [ ] extraction destination remains inside configured root;
- [ ] bounded entry count;
- [ ] bounded total uncompressed bytes;
- [ ] bounded per-entry bytes;
- [ ] compression-ratio/bomb controls where practical;
- [ ] duplicate/conflicting entry paths;
- [ ] normalization collisions, including case-insensitive filesystems;
- [ ] no propagation of executable/special mode bits into strict untrusted-data extraction;
- [ ] nested archives remain ordinary files unless the application deliberately requests another bounded extraction pass.

Do not recursively inspect/extract archives without explicit bounded application intent.

---

## 13. Public serving / download semantics

Pathwise can safely prepare/stream downloads, but web execution policy remains outside the library.

For strict untrusted-data usage:

- [ ] encourage serving through Pathwise/application-controlled download endpoints or object storage rather than executable web roots;
- [ ] provide stable safe download metadata/header helpers already consistent with `DownloadProcessor`;
- [ ] prevent user-controlled stored filenames from becoming response-header injection vectors;
- [ ] document `Content-Disposition: attachment` as an application choice for risky/untrusted document classes;
- [ ] document browser-sniffing/XSS concerns for inline HTML/SVG/XML and similar content;
- [ ] do not claim that a file being safe to download means it is safe to render inline.

Foundation owns its HTTP defaults, but Pathwise documentation should expose the risk boundary clearly.

---

## 14. "noexec" and deployment guidance

Document recommended Linux deployment hardening for upload/staging roots:

```text
noexec,nodev,nosuid
```

where operationally appropriate.

But explicitly state:

> `noexec` prevents direct execution through the mount but does not stop an interpreter from reading a file, e.g. `php /uploads/file.php` or PHP `include` when application policy permits it.

Therefore mount flags are defense in depth, not Pathwise's execution-safety claim.

Also document:

- keep upload roots outside PHP/web executable roots where possible;
- never configure the web server to route upload directories to PHP-FPM/script handlers;
- prefer separate service-account ownership/permissions for private staging;
- avoid application workers running as root.

---

## 15. Tests

### 15.1 Root/path tests

- [ ] `../` and encoded/normalized traversal variants;
- [ ] root-prefix collision (`/safe/root` vs `/safe/root-evil`);
- [ ] absolute path injection;
- [ ] Windows drive and UNC forms;
- [ ] mixed separator normalization;
- [ ] URI-scheme confusion;
- [ ] non-existing final target under trusted root;
- [ ] case-insensitive collision behavior on Windows.

### 15.2 Link/race-oriented tests

- [ ] symlink source rejected in strict mode;
- [ ] final target changed to symlink before publication fails closed;
- [ ] scan/staging file replacement remains detected;
- [ ] scan/staging file mutation remains detected;
- [ ] attacker-controlled pre-existing inode is copied/materialized before deep validation when required;
- [ ] cleanup remains deterministic after each failure path.

### 15.3 Data-only profile tests

- [ ] client filename cannot choose parent/subdirectory;
- [ ] client permission metadata is ignored;
- [ ] uploaded local artifact has no executable/special bits;
- [ ] executable-looking extension rejected when not in allowlist;
- [ ] ordinary text containing `exec(` / `pcntl_fork` / `posix_kill` is not rejected solely for token presence;
- [ ] clean malware verdict does not alter the artifact's data-only trust classification;
- [ ] final publication occurs only after all configured checks succeed.

### 15.4 Archive tests

- [ ] zip-slip variants;
- [ ] absolute/drive/UNC entry paths;
- [ ] link entries;
- [ ] duplicate normalized destinations;
- [ ] entry-count and decompression limits;
- [ ] executable/special permission metadata stripped/rejected under strict profile.

### 15.5 Scanner tests

- [ ] built-in scanners do not invoke process-execution functions;
- [ ] scanner unavailable in `REQUIRED` mode fails closed;
- [ ] malicious/suspicious/unknown verdicts fail closed;
- [ ] remote TCP remains opt-in with the existing security warning;
- [ ] scanner size/response/timeout bounds remain enforced.

---

## 16. Performance acceptance

Security hardening must not turn normal uploads into repeated whole-file copies unnecessarily.

Benchmark/measure:

- direct local upload strict path;
- local upload with malware scan materialization;
- remote-storage upload;
- resumable finalization;
- large archive validation/extraction near configured limits.

Acceptance goals:

- [ ] at most one required private materialization pass before scanner/deep validation when the source cannot be trusted directly;
- [ ] no second full copy solely for a redundant path-security check;
- [ ] root/containment checks remain O(path segments), not filesystem-wide scans;
- [ ] archive checks stream/bound metadata where practical;
- [ ] security limits reject oversized work before expensive parsing/scanning where the required authoritative metadata is already available;
- [ ] strict profile overhead for ordinary local uploads remains attributable and benchmarked.

Correctness and containment take precedence over micro-optimizations.

---

## 17. Documentation

Update Pathwise docs to include:

- [ ] `UNTRUSTED_DATA`/strict upload policy usage;
- [ ] storage vs execution trust distinction;
- [ ] safe upload-root placement;
- [ ] web-server/PHP handler separation;
- [ ] mount-flag guidance and `noexec` limitation;
- [ ] symlink/reparse/TOCTOU residual-risk model;
- [ ] archive extraction boundaries;
- [ ] built-in malware scanners never spawn privileged shell processes;
- [ ] `ext-posix` is ownership metadata support, not process-control ownership;
- [ ] integration boundary with a future process-runtime library;
- [ ] examples showing stored upload IDs/paths passed as **data**, never raw shell commands.

---

## 18. Foundation integration after Pathwise 4.1

Foundation should consume Pathwise's strict policy rather than reimplementing path/upload mechanics.

Foundation responsibilities:

- [ ] select the strict untrusted-data profile for user-facing uploads;
- [ ] configure trusted private staging and publication roots;
- [ ] require malware scanning for deployments/features that need it;
- [ ] keep public upload storage outside executable PHP roots;
- [ ] never treat `UploadResult` as executable-code authorization;
- [ ] store/reference uploaded artifacts by application-owned IDs rather than accepting arbitrary later filesystem paths;
- [ ] authorize any transition from stored artifact to process input separately;
- [ ] if an artifact is intentionally fed to a process, use the future process-runtime library with a predeclared operation/executable profile—not a user-supplied command.

ReqShield validates the surrounding request fields; Pathwise validates/materializes the file; Foundation authorizes use; the process library controls execution.

```text
ReqShield
   -> structured request intent

Pathwise
   -> bounded untrusted file artifact

Foundation
   -> authorization/capability selection

Process runtime (only if deliberately needed)
   -> controlled execution
```

---

## 19. Explicit non-goals

Do not add to Pathwise in this pass:

- shell-command sanitization;
- dangerous-PHP-function blacklists;
- PHP source-code static analysis;
- `eval`/`include` interception;
- `pcntl_fork`/`pcntl_exec` wrappers;
- `posix_kill`/`posix_setsid` process APIs;
- UID/GID privilege-dropping runtime orchestration;
- generic process execution;
- container/seccomp/AppArmor management;
- Foundation/Webrick authorization logic;
- ReqShield-style scalar/request validation;
- Omnibus worker/process orchestration.

A Runwire 1.0 sits beside/below Foundation/Omnibus and may consume Pathwise path-containment primitives where useful. It does not belong inside Pathwise.

---

## 20. Implementation order

1. Freeze the Pathwise/ReqShield/Foundation/process-library ownership boundary in docs/tests.
2. Audit current upload/root/symlink/publication code and identify duplicated containment checks.
3. Introduce the minimal strict untrusted-data upload policy/profile using existing `UploadProcessor` mechanics.
4. Centralize canonical root containment for security-sensitive local operations.
5. Harden local symlink/reparse/materialization/publication transitions.
6. Enforce non-executable/special-bit-safe modes for strict local upload/staging paths.
7. Audit archive extraction against the same containment/data-only model.
8. Audit `ext-posix` usage and document its ownership-only boundary.
9. Expand adversarial tests across Linux/Windows where CI supports them.
10. Update deployment/security documentation.
11. Run full PHPForge/static-analysis/test/benchmark gates.
12. Consume the strict profile from Foundation and remove any duplicate framework-local upload/path security mechanics.

---

## 21. Completion gate

Pathwise 4.1 runtime-security hardening is complete when:

- user uploads have an explicit strict **untrusted data** path from staging through publication;
- root containment uses canonical path-aware checks rather than naive prefix matching;
- strict local uploads cannot inherit executable or special permission bits;
- symlink/reparse/mutation checks fail closed at security-sensitive transitions;
- archive extraction remains bounded and contained;
- existing malware scanning remains typed, bounded and shell-free;
- ordinary content is not rejected merely for containing dangerous PHP/process function names;
- Pathwise never claims uploaded content is safe to execute;
- `ext-posix` remains limited to ownership/identity filesystem helpers, not process control;
- Foundation can rely on Pathwise for filesystem/upload boundaries without duplicating them;
- process execution/sandbox/privilege concerns remain cleanly owned by Foundation plus the future process-runtime library/OS;
- QA and representative performance gates remain green.

---

# Runwire execution boundary

# 1. Final ownership boundary

## Pathwise owns

- storage contexts/mounts/adapters;
- canonical root containment;
- safe path resolution;
- symlink/reparse/path-race defenses that belong to filesystem operations;
- upload staging/materialization;
- server-generated safe destination naming;
- MIME/signature/content-policy integration;
- malware-scanner contract and Pathwise-native scanner pipeline;
- archive-entry/path/size/count/depth limits;
- data-only publication/storage semantics;
- filesystem permission/metadata helpers within Pathwise scope.

## Runwire owns

- process creation/execution;
- structured executable + argv execution;
- `proc_open` I/O/deadlines/output limits;
- `pcntl`/`posix` process supervision;
- UID/GID/session/process-group mechanics;
- worker supervision;
- OS-sandbox launcher integration;
- process runtime capabilities.

## Foundation/application owns

- deciding whether a stored artifact may be passed to an executable;
- authorizing the requested operation;
- selecting Runwire execution profile;
- mapping Pathwise artifact identity/path into trusted process arguments;
- choosing an external executable malware scanner if desired;
- deployment-level OS sandbox policy.

Hard invariant:

> Pathwise makes a path/artifact safe to handle as filesystem data; it does not make that artifact safe to execute as code.

---

# 2. No Runwire production dependency

Do not add:

```json
"infocyph/runwire": "..."
```

to Pathwise production requirements merely because Foundation uses both packages.

Reasons:

- path safety is meaningful without process execution;
- uploads/storage should remain usable in FPM/serverless/non-pcntl environments;
- most Pathwise consumers never execute external processes;
- coupling process capabilities into upload handling increases attack surface;
- Foundation is the correct composition layer.

Runwire may be used as a **development/reference integration** only if Pathwise adds a cross-library test fixture proving an executable scanner adapter contract. Even that is optional; avoid dependency churn unless the fixture catches real integration bugs.

---

# 3. External malware scanner composition

Pathwise already owns `MalwareScannerInterface` / scanner-mode semantics. Preserve that abstraction.

If Foundation wants ClamAV or another executable scanner through Runwire, the composition should live above Pathwise:

```text
Pathwise UploadPipeline
       ↓
MalwareScannerInterface
       ↑
Foundation/Application scanner adapter
       ↓
Runwire ProcessRunner
       ↓
trusted scanner executable
```

The adapter may:

- obtain the Pathwise-controlled staged file;
- invoke a registered/trusted executable through Runwire structured argv;
- apply a strict timeout;
- bound stdout/stderr;
- normalize exit result into Pathwise scanner outcome;
- avoid shell construction;
- clean temporary resources deterministically.

Do **not** move Runwire `ProcessRunner`, executable allowlists or process policy into Pathwise.

---

# 4. Scanner path trust

When an executable scanner is composed externally, distinguish two paths:

```text
scanner executable path  = trusted deployment configuration / Runwire policy
staged upload path        = Pathwise-controlled untrusted-data artifact
```

The upload path may be passed as a literal argv item after Pathwise has resolved it within the intended staging root.

Do not concatenate it into a shell command string.

Correct conceptual form:

```text
executable: /usr/bin/clamscan
argv: [--no-summary, /trusted-pathwise-staging/random-id]
```

not:

```text
"clamscan " + uploadedPath
```

Pathwise is responsible for the staged artifact's filesystem boundary. Runwire is responsible for process invocation semantics.

---

# 5. Uploads remain data-only by default

Strengthen the parent-plan invariant with the named runtime:

```text
upload
  ↓
Pathwise staging/validation/scan
  ↓
data-only storage/publication
  ↓
optional later Foundation-authorized operation
  ↓
Runwire
```

There must be no automatic transition:

```text
Pathwise upload -> Runwire execute
```

simply because a file extension, MIME type or user parameter suggests executable content.

Pathwise must never call `include`, `require`, `eval`, `exec`, `system`, `shell_exec`, `passthru`, `popen`, `proc_open` or `pcntl_exec` on an uploaded artifact as part of normal upload processing.

---

# 6. `noexec` / non-executable metadata remains defense-in-depth

Keep the parent plan's distinction:

- filesystem permission/non-executable policy reduces accidental direct execution;
- `noexec` mounts are useful deployment defense-in-depth;
- neither prevents an interpreter from reading a file and interpreting it if an application intentionally passes the path to PHP or another interpreter.

Therefore the primary invariant is architectural:

> Pathwise-managed untrusted upload/storage roots are data roots and are never implicitly selected as Runwire script entrypoints.

---

# 7. `ext-posix` scope remains narrow in Pathwise

Do not expand Pathwise's optional POSIX usage merely because Runwire exists.

Pathwise may use POSIX helpers only where they directly support filesystem ownership/metadata validation.

Process lifecycle/identity operations such as these belong in Runwire:

```text
posix_kill
posix_setsid
posix_setuid
posix_seteuid
posix_setgid
posix_setegid
posix_initgroups
```

Pathwise should not wrap them.

If Pathwise needs to compare UID/GID metadata for safe filesystem ownership checks, keep that code narrowly focused on the filesystem decision and do not grow a process privilege subsystem.

---

# 8. Archive extraction boundary

Runwire does not change Pathwise archive responsibilities.

Pathwise continues to own:

- entry traversal rejection;
- absolute path rejection;
- symlink/hardlink/special-entry policy where supported;
- aggregate uncompressed bytes;
- entry count;
- nesting/depth policy;
- destination containment;
- safe publication.

Do not delegate archive extraction to an arbitrary shell command merely because Runwire can safely execute processes.

If an external archive utility is ever used for a specialized format, it must be behind an explicit Pathwise/application adapter and must preserve Pathwise's authoritative destination/entry safety policy. It must not become a bypass around Pathwise extraction checks.

---

# 9. ReqShield + Foundation + Pathwise + Runwire chain

For a privileged operation using a user-selected stored artifact, use this ownership chain:

```text
user request
    ↓
ReqShield
validates operation ID, artifact ID and structured arguments
    ↓
Foundation
resolves authorization/capability
    ↓
Pathwise
resolves artifact under intended storage root/context
    ↓
Foundation
maps to registered Runwire operation
    ↓
Runwire
executes trusted executable + literal argv under bounded policy
```

No layer should collapse this into raw shell text.

---

# 10. Runwire server uploads

Foundation's native Runwire server does not change Pathwise ownership.

Expected web upload path:

```text
Runwire HTTP transport/body stream
        ↓
Webrick request/upload representation
        ↓
Foundation upload application policy
        ↓
Pathwise UploadSource / upload pipeline
```

Runwire owns network framing/body transport limits. Webrick owns HTTP request/upload representation. Pathwise owns storage/materialization/trust-boundary mechanics.

Avoid whole-body copies merely to adapt between Runwire/Webrick/Pathwise; preserve streaming/temp-file behavior where possible.

---

# 11. Effective Pathwise changes

The Runwire decision does **not** require a new Pathwise process API.

The effective Pathwise 4.1 work remains the work already defined in the parent plan:

- untrusted-data profile;
- canonical root containment;
- symlink/reparse/race hardening;
- private staging/publication;
- permission/non-executable semantics;
- archive hardening;
- process-security non-goals.

Add only these Runwire-specific clarifications/tests if useful:

- artifact paths containing shell metacharacters remain valid data paths when otherwise legal and are not modified merely for shell safety;
- scanner/application adapters must pass paths as literal argv rather than shell strings;
- Pathwise does not auto-execute uploaded data;
- a stored `.php`, `.sh` or similar filename remains data unless the application deliberately authorizes an external operation;
- no Runwire types are referenced from Pathwise public production API.

---

# 12. Tests

Add/retain tests proving:

- shell-looking filenames are handled as filesystem data according to Pathwise filename policy, not parsed as commands;
- executable-looking file contents do not trigger Pathwise PHP/shell interpretation;
- staged path cannot escape the scan root;
- symlink replacement between validation/scan/publication is rejected according to parent plan;
- archive extraction cannot escape even if an archive contains executable-looking names;
- Pathwise functions without Runwire installed;
- any reference external-scanner adapter, if added to tests, invokes a fake/structured runner contract without shell interpolation.

Do not add tests that assert a blacklist of strings such as `exec(` inside file contents.

---

# 13. Documentation wording to normalize

In final Pathwise 4.1 docs, replace generic wording such as:

```text
future process-runtime library
process-security library
ProcessGuard
```

with:

```text
Runwire
```

where referring to the concrete Infocyph process/runtime integration.

Still describe Runwire as an **external sibling/lower runtime** rather than a Pathwise dependency.

---

# 14. Completion gate addendum

Pathwise 4.1 runtime-boundary acceptance additionally requires:

- [ ] Pathwise has no production dependency on Runwire;
- [ ] Pathwise public API contains no generic process/shell execution surface;
- [ ] Runwire is named as the owner of process execution/supervision in integration docs;
- [ ] executable-scanner composition, if documented, uses an application adapter with structured Runwire argv;
- [ ] uploads/storage remain data-only absent explicit application authorization;
- [ ] Pathwise's `ext-posix` usage remains filesystem/metadata scoped;
- [ ] Runwire-native Foundation HTTP uploads still flow Webrick → Foundation → Pathwise rather than bypassing Pathwise;
- [ ] tests do not rely on dangerous-function/content blacklists.

All other parent-plan completion criteria remain unchanged.
