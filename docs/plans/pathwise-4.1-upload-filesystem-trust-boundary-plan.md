# Pathwise 4.1 — Upload & Filesystem Trust-Boundary Hardening Plan

**Status:** final implementation plan  
**Target:** Pathwise 4.1  
**Branch:** `pathwise-4.1/foundation-runtime-security`  
**Primary consumer:** `infocyph/Foundation` runtime-security work  
**Released lower-runtime baseline:** Runwire **1.0** (`7ab48fcf224ee86838e5bf82a50b998c2aaa8a90`)  
**Pathwise branch baseline before this revision:** `e30952072ed592cc29db5c97897d2e82c08cbfe4`  
**Priority:** containment/correctness → safe materialization/publication → archive/upload hardening → persistent-runtime isolation → bounded resource use → portability → performance → ergonomics

> Pathwise owns filesystem and upload trust boundaries. It must make attacker-controlled files safe to **store, inspect, scan, move, extract and serve as data**. It must never imply that an uploaded artifact is safe to execute, and it must not become a process/shell sandbox.

This is the single canonical Pathwise 4.1 Foundation-runtime security plan. It incorporates the now-released Runwire 1.0 process/runtime boundary and replaces assumptions made while Runwire was still only planned.

---

# 1. Release objective

Pathwise 4.1 closes the remaining filesystem/upload trust-boundary work required by Foundation 3 without reducing Pathwise portability.

The target model is:

```text
untrusted bytes / names / paths
        ↓
Pathwise containment + bounded private materialization
        ↓
Pathwise validation + optional malware scanning
        ↓
controlled publication as data
        ↓
Foundation authorization / application policy
        ↓
optional privileged operation
        ↓
Runwire structured process execution
        ↓
OS / container isolation
```

Pathwise must remain fully useful on ordinary PHP deployments where Runwire, PCNTL, POSIX and persistent workers are absent.

---

# 2. Current baseline

Pathwise already has a strong base that this pass should harden rather than replace:

- PHP `>=8.4`;
- `ext-fileinfo` requirement;
- Flysystem-backed local/remote storage support;
- optional `ext-posix` ownership metadata helpers;
- deny-by-default filesystem policy support;
- upload size, extension, MIME and signature validation;
- strict content-type validation;
- bounded image/format inspection;
- framework-neutral `UploadSource` materialization;
- direct HTTP upload and trusted CLI/application ingest entry points;
- resumable/chunked upload support;
- malware-scanner contracts and a bounded clamd protocol implementation;
- private malware-scan staging;
- archive manifest/entry validation;
- `ZipArchiveExtractor` with per-entry validation, bounded copy, temporary sibling files, restrictive permissions and transactional cleanup;
- `DownloadPreparation` / range-download metadata;
- optional native filesystem acceleration through `ExecutionStrategy`, `NativeOperationsAdapter` and `NativeCommandRunner`.

The last item is important: Pathwise already owns a limited native filesystem-acceleration feature. The 4.1 plan must explicitly distinguish that feature from Runwire's generic process-execution ownership.

---

# 3. Ownership invariants

## 3.1 Pathwise owns

- path normalization and filesystem-aware canonicalization;
- configured root containment;
- local-path symlink/reparse-point checks where PHP/platform support permits them;
- upload provenance/materialization;
- private staging/quarantine;
- controlled destination naming/publication;
- extension/MIME/signature/image validation;
- malware-scanner contracts and bounded scan input;
- file/directory permission policy for Pathwise-managed untrusted artifacts;
- archive-entry path safety and extraction bounds;
- trusted-public-root filesystem resolution;
- filesystem metadata used by higher HTTP layers;
- filesystem-specific native acceleration for explicitly trusted direct-local operations;
- filesystem diagnostics/auditing that avoid unnecessary sensitive-path disclosure.

## 3.2 ReqShield owns

- structured request/operation validation;
- intent/shape validation before privileged application operations;
- no filesystem canonicalization or process execution.

## 3.3 Foundation owns

- authorization;
- tenant/user/application policy;
- deciding whether an uploaded/stored artifact may participate in a later privileged operation;
- application-level retention/quarantine/publication policy;
- DI lifetime/composition;
- selecting a Runwire process operation when external execution is required;
- persistent-request cleanup policy around Pathwise objects.

## 3.4 Runwire owns

Runwire 1.0 is authoritative for generic process mechanics:

- `Command` executable/argv modeling;
- `ProcessPolicy`;
- `ProcessRunner`;
- process I/O, timeouts and output limits;
- process termination;
- process/supervisor lifecycle;
- cancellation/deadline integration;
- structured runtime/process mechanics.

Pathwise must not grow a second general-purpose process subsystem to compete with these released APIs.

## 3.5 Webrick owns

- HTTP request/response semantics;
- Range/HEAD/conditional/cache behavior;
- static/public URL policy;
- response streaming/backpressure semantics.

Pathwise resolves filesystem trust. It does not become an HTTP server.

## 3.6 OS/container owns

- final privilege/isolation boundary;
- mounts, Unix ownership/mode enforcement, container/user namespace policy, mandatory access control and other host-level controls.

Hard invariant:

> Pathwise makes filesystem artifacts safe to treat as data. It does not make arbitrary files safe to execute.

---

# 4. Three operation classes

Pathwise 4.1 should explicitly reason about three different classes of work.

## 4.1 Untrusted-data path

Examples:

- HTTP uploads;
- resumable upload chunks;
- remote/framework-provided `UploadSource` data;
- user-controlled ZIP archives;
- request-derived public-file paths;
- names/paths supplied by an untrusted client.

Requirements:

- Pathwise-owned validation and containment are mandatory;
- private staging before publication;
- no caller-controlled command/executable selection;
- no raw native `unzip` extraction;
- no shell/process execution of the artifact;
- bounded bytes/count/time where Pathwise can enforce them;
- fail closed where required security services are unavailable.

## 4.2 Trusted direct-local filesystem acceleration

Examples:

- application-controlled local tree copy;
- trusted local compression;
- trusted local text search;
- administrative/local file operations whose source/destination have already been authorized by the application.

Existing `ExecutionStrategy::PHP|AUTO|NATIVE` behavior may remain here.

Native acceleration is:

- an optimization;
- direct-local only;
- shell-free;
- bounded;
- never the filesystem security boundary;
- never proof that a path supplied by a caller is authorized.

## 4.3 Privileged/external execution

Examples:

- an external malware scanner executable;
- a media converter;
- PDF/image processing executable;
- an application-specific trusted command consuming a Pathwise-resolved artifact.

Correct composition:

```text
untrusted input
    ↓
ReqShield validates structured intent
    ↓
Foundation authorizes operation
    ↓
Pathwise resolves/materializes trusted artifact path
    ↓
Runwire Command + ProcessRunner
    ↓
trusted executable
```

Pathwise must not expose a convenience API that silently turns an uploaded file into an executable process input without the Foundation authorization step.

---

# 5. Resolve the existing `NativeCommandRunner` overlap

The current branch publicly ships `Infocyph\Pathwise\Native\NativeCommandRunner`, which already implements shell-free `proc_open()` execution, bounded stdout/stderr, deadlines and termination. It is used by `NativeOperationsAdapter` for filesystem-specific tools such as `cp`, `rsync`, `grep`, `zip` and `unzip`.

That implementation predates released Runwire 1.0 and now overlaps Runwire's generic `ProcessRunner` responsibility.

## 5.1 Pathwise 4.1 decision

Do **not** remove the class in a 4.1 minor release if doing so would break existing consumers.

Instead:

- retain source compatibility for 4.1;
- stop presenting `NativeCommandRunner` as the preferred generic process API;
- mark direct generic use deprecated for application process execution;
- document it as legacy/internal infrastructure behind Pathwise's trusted filesystem-native acceleration;
- do not add new generic process features to it;
- do not add supervisor, cancellation graph, sandbox, user/group switching or arbitrary shell facilities;
- keep shell-free argument-vector execution and finite limits for the remaining internal/native-acceleration use;
- plan to internalize/remove the generic public runner in the next major if ecosystem usage permits.

`NativeOperationsAdapter` may remain the filesystem-specific acceleration surface because its operations are Pathwise-owned filesystem behaviors rather than an arbitrary process API.

## 5.2 Foundation rule

Foundation must **not** use Pathwise `NativeCommandRunner` for general command execution.

Use released Runwire 1.0:

```text
Runwire\Process\Command
Runwire\Process\ProcessPolicy
Runwire\Process\ProcessRunner
```

when Foundation needs a process.

## 5.3 Dependency rule

Pathwise does **not** gain a production dependency on Runwire.

Reasons:

- Pathwise must remain usable on shared hosting, FPM, CLI and serverless/request-owned PHP;
- Pathwise's ordinary filesystem/upload APIs do not need a process runtime;
- requiring Runwire would unnecessarily inherit Runwire's runtime/platform constraints;
- external process composition belongs to the application layer.

No `Runwire` public type appears in Pathwise production signatures.

---

# 6. Strict untrusted-upload profile

Keep/add one clearly documented strict profile for internet-facing uploads rather than an oversized policy DSL.

The strict profile should guarantee at least:

- authoritative actual byte-size limit;
- finite chunk count and chunk size;
- server-generated final name;
- original client filename retained as metadata only;
- blocked extension policy plus explicit allowlist where configured;
- MIME allowlisting;
- extension ↔ MIME consistency when applicable;
- signature/magic verification where supported;
- image/format-specific structural validation where applicable;
- private staging before final publication;
- restrictive staging permissions;
- symlink/special-file rejection for local untrusted materialization;
- optional/required malware scanning according to explicit mode;
- post-scan identity/mutation revalidation;
- controlled publication to an authorized root;
- no executable-bit preservation from source/client material;
- deterministic cleanup on failure/cancellation/exception.

Do not advertise an extension blocklist as the primary security boundary. A `.txt` file can contain executable source, and a `.php` file can still be safely stored as inert data when policy allows it.

Do not reject content merely because it contains strings such as `exec(`, `system(` or shell syntax. Strings are data until another layer interprets them.

---

# 7. Input provenance and materialization

Pathwise must keep source provenance explicit.

Current APIs already distinguish:

- HTTP upload processing;
- trusted CLI/application `ingestFile()`;
- framework-neutral `UploadSource` materialization;
- resumable chunk ingestion.

4.1 must ensure:

- HTTP-upload checks are not silently applied to trusted non-HTTP sources where PHP upload provenance is unavailable;
- trusted ingest does not automatically mean trusted *content*;
- `UploadSource` materializes into a Pathwise-owned private local staging location before local-only inspection/scanning requires it;
- one source is not repeatedly copied/materialized for each validator;
- local temporary materialization is operation-owned and cleaned in `finally`;
- materialization does not preserve attacker-controlled executable/special permission bits;
- remote/object-storage sources are treated as data streams, not local trusted paths.

---

# 8. Canonical containment primitive

Audit all containment decisions and converge them on one internal, filesystem-aware rule.

Never use a naive string prefix test such as:

```php
str_starts_with($candidate, $root)
```

for security containment.

Correct containment must account for:

- normalized separator boundaries;
- `.` / `..`;
- Unix absolute roots;
- Windows drive letters;
- Windows case rules where applicable;
- UNC roots;
- stream/storage schemes;
- sibling-prefix ambiguity (`/root/a` vs `/root/ab`);
- existing-path canonicalization;
- nearest existing parent when the target does not yet exist.

Reuse/consolidate existing `PathHelper`, `FlysystemHelper`, ZIP-entry and local-path rules rather than maintaining several subtly different containment algorithms.

Do not freeze a new broad public containment API merely for this pass unless a real consumer needs it; an internal canonical primitive is sufficient for 4.1.

---

# 9. Persistent-runtime path cache hardening

`PathHelper::normalize()` currently keeps a process-wide static normalization cache with up to 1,024 arbitrary path strings.

That was less significant under request-owned PHP, but under persistent H2/H3 workers it can retain request-derived/high-cardinality path data across executions and be churned by an attacker.

4.1 should:

- remove arbitrary request-derived paths from process-global static caching;
- prefer uncached normalization on the security-sensitive/untrusted path;
- if benchmarking proves caching materially useful, cache only immutable/trusted configured roots or another explicitly bounded trusted keyspace;
- never cache tenant/user/upload identifiers merely because they look like paths;
- include persistent-worker memory/churn tests.

Path normalization is normally far cheaper than filesystem I/O. Correct isolation takes precedence over an unproven global micro-optimization.

---

# 10. Symlink, reparse-point and TOCTOU hardening

For strict local operations:

- reject a source that unexpectedly becomes a symlink;
- inspect path components where meaningful before traversing/publishing;
- validate the nearest existing destination parent;
- avoid following untrusted link chains outside the configured root;
- use unique random temporary names;
- create temporary files exclusively where possible (`xb` / equivalent);
- re-check relevant identity/metadata after long-running scan/validation steps;
- publish with atomic rename when source and destination are on the same filesystem;
- otherwise copy to a controlled destination-side temporary file and publish only after complete verification;
- clean partial temporary artifacts on failure.

Pathwise should document the limit honestly: pure PHP path checks cannot create a perfect kernel-level race-proof sandbox against a hostile process concurrently mutating the same directory tree. Directory ownership, mount permissions and OS/container isolation remain defense in depth.

---

# 11. File identity across validation/scanning/publication

A validated filename is not sufficient. The artifact itself must remain the same artifact.

For local strict materialization, retain/recheck enough metadata to detect suspicious replacement or mutation between important phases, such as:

- actual size;
- file type/link state;
- modification metadata;
- inode/device identity where available and meaningful;
- a digest when already required by the operation.

Do not hash large files repeatedly only to create the appearance of security. Prefer one streaming pass or metadata identity where adequate, and benchmark any extra digest cost.

After an external or daemon malware scan, revalidate the staged file before final publication.

---

# 12. Permission policy

Pathwise-managed untrusted local artifacts should default to restrictive permissions.

Recommended intent:

```text
private staging directory : 0700
staged file               : 0600
published private file    : application policy, never attacker-preserved executable mode
published public data     : only the minimum read bits required by deployment policy
```

Requirements:

- do not preserve source executable/setuid/setgid/sticky bits for untrusted artifacts;
- do not assume `chmod()` is meaningful for remote Flysystem adapters;
- do not fail remote storage merely because POSIX mode bits are unavailable;
- expose clear capability/behavior differences rather than pretending every filesystem supports Unix permissions.

---

# 13. POSIX boundary

`ext-posix` remains optional and filesystem-focused inside Pathwise.

Acceptable uses include filesystem ownership/identity metadata when available.

Do not add Pathwise ownership of:

- `posix_kill`;
- process groups/sessions;
- signal routing;
- `setuid`/`setgid` process privilege transitions;
- daemon/process supervision.

Those are Runwire/OS concerns.

Pathwise must continue to work without `ext-posix`.

---

# 14. Archive trust boundary

Archive extraction is a filesystem publication operation and therefore remains a Pathwise concern.

For user-controlled/untrusted ZIP input, the authoritative path is:

```text
archive
   ↓
manifest + ZipEntryValidator
   ↓
entry count/name/type/size policy
   ↓
ZipArchiveExtractor
   ↓
per-entry controlled temporary file
   ↓
revalidation
   ↓
controlled publish
```

Required bounds include:

- maximum entry count;
- maximum total uncompressed bytes;
- maximum per-entry bytes;
- maximum path/name length;
- maximum nesting/path depth if required by policy;
- traversal rejection;
- absolute path rejection;
- Windows drive/UNC escape rejection;
- symlink/hard-link/special-entry rejection unless explicitly supported under a safe policy;
- duplicate/collision handling;
- normalized-name collisions;
- cleanup after partial failure.

## 14.1 Native `unzip` decision

Current `NativeOperationsAdapter::decompressZip()` can invoke `unzip` directly into a destination. That path must **not** be used as the strict/untrusted archive extractor because it bypasses Pathwise's authoritative per-entry validation/publication sequence.

For 4.1:

- strict/untrusted ZIP extraction always uses the Pathwise validated extractor;
- `ExecutionStrategy::AUTO` must not silently choose raw native `unzip` for the strict security path;
- `ExecutionStrategy::NATIVE` must not override the untrusted archive security invariant;
- native ZIP extraction may remain documented only for trusted direct-local administrative data where the caller intentionally accepts the native tool's semantics;
- no performance result may justify bypassing `ZipEntryValidator` / `ZipArchiveExtractor` for hostile archives.

Native ZIP **creation** for trusted local inputs may remain an acceleration because it does not publish attacker-controlled archive entries into a filesystem tree.

---

# 15. Archive bombs and decompression work

Pathwise must bound both disk growth and work amplification as far as practical.

Do not rely only on compressed-file size.

At minimum track/limit:

- declared uncompressed size;
- actual bytes copied;
- total bytes published;
- entry count;
- suspicious size mismatch;
- malformed/truncated entries;
- nested archive policy at the application layer.

Pathwise 4.1 does not need recursive arbitrary-archive malware/content interpretation. Nested archive recursion is an explicit application/scanner policy, not an automatic behavior.

---

# 16. Malware-scanner contract

Pathwise retains the framework-neutral `MalwareScannerInterface` boundary.

Built-in clamd integration is preferred where appropriate because it communicates with the daemon protocol directly and does not require Pathwise to spawn a process.

Rules:

- `WHEN_CONFIGURED` remains opportunistic/explicit;
- a strict `REQUIRED`/fail-closed mode must fail if no scanner is available or scanning cannot complete;
- scan input must remain bounded/private;
- remote clamd TCP remains explicit because the clamd protocol itself does not provide application authentication/encryption;
- scanner provider/status should remain diagnosable without leaking secrets;
- rejected or failed scans leave no published artifact;
- scan temporary files are cleaned deterministically.

---

# 17. External scanner composition with Runwire 1.0

Pathwise must not add an executable-path option that internally calls a generic process runner for hostile uploads.

Correct external-scanner composition is:

```text
Pathwise UploadProcessor
       ↓
MalwareScannerInterface
       ↑
Foundation/Application scanner adapter
       ↓
Runwire Process\Command
       ↓
Runwire Process\ProcessRunner
       ↓
trusted scanner executable
```

The application adapter is responsible for:

- fixed/trusted executable selection;
- literal argv construction;
- finite deadline;
- finite stdout/stderr limits;
- exit-status interpretation;
- cancellation policy;
- mapping the result to Pathwise's scanner verdict contract.

The Pathwise-resolved staged path is data in argv, not a command string.

No Runwire production dependency is added to Pathwise.

---

# 18. No execution of uploaded artifacts

The normal upload/extraction pipeline must never execute an uploaded artifact through:

- `include` / `require`;
- `eval`;
- `exec`;
- `system`;
- `shell_exec`;
- `passthru`;
- `popen`;
- `proc_open`;
- `pcntl_exec`;
- an interpreter selected from the upload's extension/name/content.

This rule does **not** mean Pathwise can never internally use `proc_open()` for its legacy trusted native-filesystem accelerator in 4.1. The distinction is important:

```text
BAD:
user file/path chooses what program is executed or becomes executable code

ALLOWED LEGACY ACCELERATION:
Pathwise chooses a fixed filesystem tool and passes already-authorized trusted-local paths as literal argv
```

For Foundation privileged operations, generic execution moves to Runwire.

---

# 19. Trusted public/static-file resolution for Webrick

Webrick 5's Runwire path introduces a useful fast path for static/public files, but filesystem authorization remains Pathwise/Foundation-owned.

Correct flow:

```text
Runwire HTTP request
       ↓
Webrick/Foundation static-route/public-asset policy
       ↓
Pathwise trusted public-root resolution + containment
       ↓
Pathwise file metadata / DownloadPreparation-compatible data
       ↓
Webrick HEAD/Range/conditional/cache semantics
       ↓
Runwire response/file writer
       ↓
client
```

Requirements:

- Runwire never maps a raw URL path directly to disk;
- URL/static eligibility remains Webrick/Foundation policy;
- Pathwise receives a configured trusted root plus a candidate relative path and enforces containment;
- traversal and root escape fail closed;
- symlink policy is explicit;
- private/quarantine/temp/chunk directories are never exposed merely because they are filesystem descendants elsewhere;
- caller policy decides dotfile/public-name eligibility; containment remains Pathwise's job;
- avoid repeating stat/hash work if Pathwise has already produced trustworthy immutable metadata for the response;
- the response layer may use zero-copy/sendfile-style transport only after Pathwise/Foundation trust resolution;
- a `DownloadPreparation::path` or equivalent is trusted only because of the preceding Pathwise resolution, never because it is a string in a result object.

Pathwise must not depend on Webrick or Runwire to implement this.

---

# 20. Download/range preparation boundary

Keep Pathwise download preparation focused on filesystem facts:

- resolved path/artifact;
- name;
- MIME type;
- size;
- last modification time;
- ETag/digest metadata where already required;
- selected byte range metadata;
- headers that are filesystem-derived/application-safe.

Webrick remains authoritative for HTTP semantics and transport selection.

Avoid an API that gives the lower runtime authority to open any caller-provided path. Prefer already-resolved local path/handle metadata after trust policy has completed.

---

# 21. Persistent-worker and concurrency safety

Foundation/Runwire/Webrick may handle many requests in one process and may interleave HTTP/2 or HTTP/3 streams. Pathwise 4.1 must be safe to compose in that environment without becoming runtime-aware.

Rules:

- no process-global "current upload", "current user", "current tenant" or "current destination" state;
- request/operation mutable state lives on operation-owned objects;
- temporary files/directories are owned by one operation and cleaned in `finally`;
- resumable-upload state is keyed/locked by upload identity, not ambient request globals;
- scanner result/status for one request cannot bleed into another;
- filesystem policy/configuration shared as a singleton must be immutable or treated as read-only after bootstrap;
- mutable `UploadProcessor` configuration must not be shared concurrently across unrelated requests unless the application provides synchronization and proves it safe;
- Foundation should compose mutable upload processors as transient/request-scoped instances, or build them from immutable application policy;
- Pathwise does not gain an InterMix dependency merely to enforce this composition rule.

The Pathwise test suite should include overlapping operations that deliberately use different roots/policies/scanner fixtures and prove isolation.

---

# 22. Static/process-wide state audit

Audit Pathwise static state for persistent-runtime safety.

At minimum inspect:

- `PathHelper` normalization cache;
- filesystem adapter registries/caches;
- global/static configuration helpers;
- native command capability caches;
- temporary resource registries;
- logger/scanner references retained by long-lived objects.

Classification should be simple:

```text
immutable bootstrap state          -> may be process lifetime
bounded trusted configuration cache -> may be process lifetime if justified
request/tenant/upload-derived state -> must not be process lifetime
operation temporary state           -> operation lifetime only
```

Do not introduce a general runtime context or service locator into Pathwise.

---

# 23. Cancellation/deadline boundary

Runwire 1.0 has authoritative request cancellation/deadline primitives, but Pathwise remains runtime-neutral.

Therefore:

- no Runwire `CancellationToken`/`RequestContext` types in Pathwise production APIs;
- ordinary PHP filesystem operations remain synchronous;
- Foundation may check runtime cancellation between Pathwise operations;
- external scanner/process work inherits cancellation/deadline through the Foundation → Runwire adapter;
- long Pathwise loops may gain a small framework-neutral cooperative callback only if a real measured need exists and the API remains useful outside Runwire;
- do not build an async filesystem scheduler in Pathwise 4.1.

---

# 24. Remote/Flysystem boundary

Local-filesystem security assumptions must not be projected onto object storage or remote adapters.

Pathwise should explicitly distinguish capabilities such as:

- atomic rename availability;
- Unix mode bits;
- inode/device identity;
- symlink semantics;
- random-access/range support;
- local executable/native-tool eligibility.

Rules:

- native filesystem commands are direct-local only;
- remote storage never becomes a local shell argument unless first materialized through the explicit private staging boundary;
- remote publication uses the strongest atomic/temporary-object semantics the adapter can provide;
- unsupported local-only checks are not silently claimed as successful security guarantees.

---

# 25. Audit/diagnostics

Security diagnostics should report enough to operate the system without leaking sensitive data by default.

Useful fields include:

- operation class/profile;
- backend/scheme;
- result category;
- scanner provider/status;
- native acceleration selected/fallback reason for trusted operations;
- bytes/entry counts;
- policy rejection category;
- archive rejection category.

Avoid logging:

- full uploaded contents;
- secrets/tokens;
- remote credentials;
- complete sensitive absolute paths when an operation/artifact identifier is sufficient.

---

# 26. Native filesystem acceleration policy

The existing `ExecutionStrategy` remains useful for Pathwise standalone users.

For trusted direct-local operations:

```text
PHP     -> always portable PHP implementation
AUTO    -> native filesystem tool when eligible, otherwise PHP fallback
NATIVE  -> require eligible native implementation or fail
```

4.1 hardening rules:

- fixed executable chosen by Pathwise, not request input;
- argument-vector execution only;
- no shell fragments;
- no `sh -c`, `cmd /c`, PowerShell script string, or equivalent interpolation surface;
- finite deadline;
- finite stdout/stderr capture;
- working directory explicit where needed;
- path arguments passed literally;
- command capability detection must not become attacker-controlled high-cardinality global state;
- native failure in `AUTO` may fall back only when fallback preserves the same security semantics;
- security-sensitive untrusted archive extraction never falls back *to* a weaker native implementation.

Performance is a reason to keep native acceleration for trusted filesystem work, not a reason to weaken the untrusted-data path.

---

# 27. Performance architecture

Optimize after the trust boundary is correct.

Preserve/target:

- one local materialization per upload/source when possible;
- streaming validation/scanning where APIs allow it;
- no whole-file in-memory copy for large uploads;
- no repeated digest of the same large file without a consumer need;
- atomic rename instead of copy when same-filesystem publication permits it;
- direct local stream/range support for downloads;
- native copy/compression/search acceleration only on trusted-local paths;
- no process startup for ordinary Pathwise operations when PHP implementation is adequate;
- no process-global cache of attacker-derived paths.

Benchmark separately:

1. path normalization/containment;
2. upload validation/materialization;
3. malware staging/scan overhead;
4. strict ZIP extraction;
5. trusted native vs PHP copy/compression/search;
6. public-file resolution/download preparation;
7. persistent-worker repeated operations and memory growth.

Do not benchmark away required security checks.

---

# 28. Test matrix

Add/retain focused tests for the following.

## 28.1 Path containment

- `../` traversal;
- mixed separators;
- repeated separators;
- encoded/decoded application candidate behavior where caller supplies decoded path;
- sibling-prefix escape;
- Unix absolute path;
- Windows drive root;
- drive-relative path rejection;
- UNC root;
- case behavior where relevant;
- existing and not-yet-existing destination;
- symlinked parent/source/destination;
- root replacement/race simulations where practical.

## 28.2 Upload pipeline

- HTTP provenance;
- trusted ingest provenance;
- framework-neutral `UploadSource`;
- actual size mismatch;
- allowed/blocked extension;
- MIME mismatch;
- signature mismatch;
- image structural validation;
- random final naming;
- restrictive staging permissions;
- source mutation during validation/scan;
- cleanup after every failure path;
- resumable assembly + final validation/scan;
- hostile filename containing shell metacharacters remains inert data.

## 28.3 Malware scanning

- configured scanner success;
- infected verdict;
- timeout/failure;
- required scanner unavailable → fail closed;
- clamd local/TCP policy;
- post-scan mutation detection;
- Foundation fixture using Runwire `ProcessRunner` through an application scanner adapter;
- Pathwise core loads and works with Runwire absent.

## 28.4 Archives

- traversal entries;
- absolute entries;
- Windows drive/UNC entries;
- symlink/special entries;
- duplicate/normalized collisions;
- entry-count overflow;
- total uncompressed overflow;
- per-entry overflow;
- truncated/malformed archive;
- extraction cleanup after mid-stream failure;
- strict/untrusted path proves raw `unzip`/`NativeCommandRunner` is never selected;
- trusted-local native unzip behavior, if retained, is clearly separate.

## 28.5 Native acceleration

- argument vectors remain shell-free;
- output/deadline limits;
- no caller-selected executable in filesystem convenience methods;
- `AUTO` fallback semantic parity;
- `NATIVE` unavailable failure;
- direct `NativeCommandRunner` deprecation/source compatibility for 4.1;
- no new generic process behavior.

## 28.6 Persistent runtime

- repeated path normalization with attacker-like high cardinality has bounded/stable memory;
- two concurrent/interleaved upload processors with different configuration do not bleed state;
- temp artifacts cleaned after exception/cancellation boundary;
- no per-request static current path/upload state;
- long-lived read-only policy objects remain stable.

## 28.7 Public/static resolution

- allowed file under trusted root;
- traversal rejection;
- symlink escape rejection according to policy;
- private/quarantine/temp paths never exposed;
- range/download metadata correctness;
- Foundation/Webrick fixture consumes resolved artifact without reparsing raw URL into a filesystem path.

---

# 29. Foundation acceptance flow

Recommended upload flow:

```text
Runwire/Webrick HTTP body
        ↓
Foundation request policy
        ↓
Pathwise UploadSource / UploadProcessor
        ↓
private materialization
        ↓
Pathwise validation
        ↓
MalwareScannerInterface
        ↓
controlled publication as data
        ↓
application stores artifact identifier/metadata
```

Later privileged use is separate:

```text
artifact identifier + requested operation
        ↓
ReqShield structured validation
        ↓
Foundation authorization
        ↓
Pathwise trusted artifact resolution
        ↓
Runwire Command / ProcessRunner
        ↓
trusted executable / OS isolation
```

Do not collapse these into an "upload and execute" API.

---

# 30. Dependency policy

Pathwise 4.1 production dependencies must remain filesystem/storage focused.

Do not add production dependencies on:

- Runwire;
- Webrick;
- Foundation;
- InterMix;
- ReqShield.

Cross-library integration belongs in Foundation/application adapters and integration tests/fixtures.

This preserves Pathwise as a reusable filesystem/upload library rather than turning it into a Foundation runtime component.

---

# 31. Documentation updates

Update public docs to clearly distinguish:

- untrusted upload/data pipeline;
- trusted CLI/application ingest;
- trusted direct-local native filesystem acceleration;
- deprecated generic direct use of `NativeCommandRunner` for application process execution;
- external scanner composition through an application adapter + Runwire;
- secure ZIP extraction vs trusted native unzip;
- local vs remote/Flysystem guarantees;
- symlink/TOCTOU limits;
- permissions;
- persistent-worker object lifetime guidance;
- public/static-file trusted-root resolution;
- Runwire remaining optional/not a Pathwise dependency.

Avoid language claiming Pathwise "sanitizes" a file into something safe to execute.

---

# 32. Implementation order

```text
1. Freeze ownership: Pathwise filesystem trust; Runwire generic process execution.
2. Audit NativeCommandRunner / NativeOperationsAdapter public usage and documentation.
3. Deprecate generic application use of NativeCommandRunner without breaking 4.1 consumers.
4. Enforce strict/untrusted archive extraction through ZipEntryValidator + ZipArchiveExtractor only.
5. Finalize/document the strict untrusted-upload profile and publication rules.
6. Consolidate canonical path/root containment decisions.
7. Harden local symlink/reparse/TOCTOU and permission handling.
8. Remove arbitrary request-derived paths from PathHelper process-global normalization caching.
9. Recheck scan identity and fail-closed scanner behavior.
10. Add trusted public-root resolution acceptance for Webrick/Foundation static delivery.
11. Audit persistent-worker mutable/static state and Foundation composition guidance.
12. Add Runwire external-scanner integration fixture without a production dependency.
13. Expand security/concurrency/archive/native tests.
14. Benchmark security-preserving hot paths and tune only measured regressions.
15. Update docs and close Foundation Pathwise acceptance.
```

No permanent cross-repository CI design is required in this Pathwise plan; Foundation release work may decide the final automation matrix later.

---

# 33. Completion gate

Pathwise 4.1 Foundation-runtime acceptance closes only when:

- [ ] one canonical containment rule is used by security-sensitive local path/publication decisions;
- [ ] traversal, symlink/reparse and destination-parent rules are tested on supported platforms;
- [ ] strict uploads use private bounded staging and controlled publication;
- [ ] original client filenames never become authoritative destination paths;
- [ ] strict scanner-required mode fails closed;
- [ ] post-scan mutation/identity handling is deterministic;
- [ ] untrusted ZIP extraction always uses Pathwise validated entry extraction;
- [ ] raw native `unzip` cannot be selected for the strict/untrusted archive path;
- [ ] archive count/size/path/link bounds are release-tested;
- [ ] `NativeCommandRunner` is not expanded as a generic process API and direct application use is deprecated/documented for migration;
- [ ] trusted native filesystem acceleration remains shell-free, bounded and direct-local only;
- [ ] Foundation uses released Runwire 1.0 for generic/external process execution;
- [ ] Pathwise has no Runwire production dependency or Runwire public types;
- [ ] Pathwise works without PCNTL/POSIX/Runwire where the selected filesystem feature does not require them;
- [ ] arbitrary request-derived paths are not retained by a process-global normalization cache;
- [ ] mutable upload processor state is not shared unsafely across persistent concurrent requests;
- [ ] temporary upload/archive/scan resources clean up on success and failure;
- [ ] trusted public-root resolution composes with Webrick/Runwire without giving Runwire raw path authority;
- [ ] public/static resolution rejects traversal/private/quarantine exposure;
- [ ] local and remote storage guarantees are documented honestly;
- [ ] no upload/file-validation API claims to make arbitrary content safe to execute;
- [ ] security-preserving benchmark evidence shows no unacceptable regression in common upload/path/file flows;
- [ ] Foundation 3 integration passes upload, archive, scanner, static-file and privileged-operation boundary acceptance.

---

# 34. Explicit non-goals

Do not add to Pathwise 4.1 as part of this pass:

- a generic new process runner/supervisor;
- shell command execution API;
- arbitrary executable discovery/execution for untrusted callers;
- PCNTL worker management;
- signal orchestration;
- event loop;
- coroutine scheduler;
- Runwire cancellation types;
- Foundation DI/container integration;
- Webrick HTTP routing/response writer;
- ReqShield request validation;
- OS/container sandbox implementation;
- a claim that uploaded PHP/source/binaries are safe to execute;
- a raw `unzip` fast path for hostile archives;
- an unbounded process-global path cache.

Runwire owns generic process/runtime mechanics. Pathwise owns filesystem/upload trust. Foundation owns authorization/composition. Webrick owns HTTP semantics. ReqShield owns structured validation. The OS/container remains the final isolation boundary.
