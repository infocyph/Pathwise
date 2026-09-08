# Pathwise 4 — Final Development Plan

## Status

Target release: **Pathwise 4.0**

Reason for major release:

- the upload malware-scanner API is intentionally breaking;
- security defaults are being tightened;
- atomic-write semantics are being corrected rather than preserved for compatibility;
- queue reservation semantics require a lease-token contract;
- native execution needs bounded-process semantics;
- the public documentation/API surface will be normalized around the hardened architecture.

Current development branch: `pathwise-3.2/storage-context`

Current pull request: **#21**

Baseline main commit: `8226cf42747ae131486063cad39335d6dfc1c7f7`

The branch name predates the decision to ship the work as a major release. The release target from this plan forward is **4.0**, not 3.2.

## Execution tracker

Last tracker update: **2026-09-08**

Legend:

- `[X]` complete and already implemented on the development branch;
- `[~]` actively being implemented or still needs its final acceptance/CI closure;
- `[ ]` pending;
- `[!]` blocked by an external prerequisite or release dependency.

Current active batch: **Batch 7 — malware policy modes and scanner providers**.

| Area | Status | Tracking note |
| --- | --- | --- |
| Plan freeze / Pathwise 4 scope | [X] | Major-release scope, ownership rules, work order, documentation gate, and release gates are frozen in this file. |
| Batch 1 — instance-scoped storage context | [X] | `StorageContext` and per-instance storage/driver resolution are implemented. |
| Batch 2 — typed upload-source ownership | [X] | `UploadSource` materialization and cleanup ownership are implemented. |
| Batch 3 — hardened malware boundary | [X] | Typed request/verdict contract, private staging, fail-closed verdicts, mutation detection, and chunk-finalization scanning are implemented; provider/mode expansion is tracked in Batch 7. |
| Batch 4 — prepared range-aware download streaming | [X] | Range-aware iterable streaming and deterministic resource closure are implemented. |
| Batch 5 — generic safe symlink management | [X] | Generic root-contained link create/status/remove behavior is implemented. |
| Batch 6 — production PSR-3 dependency | [X] | `psr/log ^3.0.2` is a direct production dependency. |
| Batch 7.1 — malware scan modes/status | [~] | `OFF`, `WHEN_CONFIGURED`, and `REQUIRED` behavior is implemented; final status/provider diagnostics and QA closure remain. |
| Batch 7.2 — ClamAV daemon adapter | [~] | INSTREAM adapter exists; stream-size bounding, protocol tests, static-analysis/style cleanup, and final CI closure are in progress. |
| Batch 7.3 — LMD integration guidance | [ ] | Document the preferred ClamAV + LMD host deployment without root/sudo coupling in request workers. |
| Batch 7.4 — other scanner engines | [~] | Core typed scanner contract is already generic; provider examples/documentation remain. |
| Batch 7.5 — scanner acceptance suite | [~] | Core malware boundary tests pass; deterministic fake-clamd protocol/error/timeout/limit coverage is being completed. |
| Batch 8 — security defaults / atomic guarantees / permissions | [ ] | Starts after Batch 7 closes. |
| Batch 9 — bounded native execution | [ ] | Pending. |
| Batch 10 — file queue lease correctness/durability | [ ] | Pending. |
| Batch 11 — archive/parser hardening | [ ] | Pending. |
| Batch 12 — static/global-state cleanup | [ ] | Pending major-version cleanup. |
| Batch 13 — observability/retention/indexing/watcher review | [ ] | Pending whole-library subsystem audit. |
| Batch 14 — complete Pathwise 4 documentation | [ ] | Release blocker; starts after public APIs are stable, with feature docs added earlier when useful. |
| Batch 15 — performance/stress/release gates | [ ] | Final acceptance only after functional/security batches stabilize. |
| Pathwise 4.0 release | [!] | Blocked until Batches 7–15 and all release gates pass. |
| Foundation 3 / Point 26.5 consumption | [!] | Blocked until Pathwise 4.0 is released; Foundation then raises its floor and removes duplicated generic filesystem mechanics. |

Tracker maintenance rule: update this table whenever a batch starts, closes, is split, or gains a release-blocking finding. A batch is marked `[X]` only after its implementation and relevant acceptance checks are complete; writing code alone is not enough.

## Principles

1. **Security before compatibility.** Breaking changes are acceptable when they remove ambiguous, unsafe, or misleading behavior.
2. **Fail closed at explicit security boundaries.** Malware scanning, policy enforcement, archive validation, queue lease ownership, and bounded process execution must never silently degrade.
3. **Do not claim guarantees Pathwise cannot provide.** In particular, remote staged replacement is not described as an atomic filesystem rename.
4. **Keep application policy outside Pathwise.** Pathwise owns generic filesystem/storage/upload/download/security mechanics; Foundation owns application composition and policy.
5. **Avoid process-global mutable state for persistent runtimes.** Instance-scoped contexts are the preferred integration surface.
6. **Prefer typed contracts/results over magic booleans and loosely-shaped arrays for new major APIs.**
7. **No hidden privilege requirements.** A normal PHP/web worker must not need root to use Pathwise.
8. **Document every public capability with complete working examples.** A feature is not release-complete if consumers cannot discover and correctly compose it from the documentation.

---

# Completed foundation batches

These batches were implemented before the 4.0 decision and remain part of the 4.0 release.

## Batch 1 — instance-scoped storage context — complete

- `StorageContext` owns named filesystem configuration and default selection per instance.
- Lazy per-context `FilesystemOperator` caching.
- Per-context custom driver factories without process-global custom-driver fall-through.
- Portable logical `name://path` resolution.
- Local-root capability/path resolution.
- Traversal/null-byte/Unix/Windows/UNC absolute logical-path rejection.
- Multiple application contexts may reuse the same logical disk names without cross-talk.

## Batch 2 — typed upload-source ownership — complete

- Framework-neutral `UploadSource`.
- Path, stream, and framework-mover sources.
- Explicit borrowed/owned source semantics.
- Pathwise-owned private staging.
- Authoritative materialized size.
- Deterministic cleanup on success and failure.
- Typed single-upload and resumable-chunk entry points.

## Batch 3 — hardened malware boundary — complete, being extended for 4.0

Already implemented:

- typed `MalwareScannerInterface`;
- typed `MalwareScanRequest`;
- explicit `MalwareScanVerdict`;
- only `CLEAN` is accepted;
- `MALICIOUS`, `SUSPICIOUS`, and `UNKNOWN` fail closed;
- scan runs before MIME/signature/image parsing;
- Pathwise gives scanners a private local regular-file copy;
- scanner/backend errors expose stable public upload errors while preserving the previous exception;
- scan-input mutation is detected, including same-size byte mutation;
- actual size is checked before scanning;
- mounted/remote inputs are copied locally before scanning;
- full assembled chunk uploads are scanned before publication.

4.0 extension is defined in Batch 7 below.

## Batch 4 — prepared range-aware download streaming — complete

- Pathwise owns seek/discard/range-length mechanics.
- `DownloadProcessor::streamChunks()` provides iterable response-body integration.
- `streamDownload()` uses the same core.
- Resource closure is deterministic on completion, exception, and early iterator disposal.
- Prepared metadata is revalidated before streaming.

## Batch 5 — generic safe symlink management — complete

- explicit link-root and target-root boundaries;
- ancestor/symlink escape protection;
- target creation only after containment verification;
- no-clobber direct final symlink creation;
- current-target verification for status/removal;
- broken managed-link recognition/removal;
- Windows directory-symlink behavior handled explicitly.

## Batch 6 — production PSR-3 dependency — complete

- `psr/log ^3.0.2` is a direct production dependency.
- A concrete logger implementation remains application-owned.

---

# Pathwise 4 implementation batches

## Batch 7 — malware policy modes and scanner providers

### 7.1 Replace the old required-scanner boolean with an explicit mode

Introduce `MalwareScanMode`:

- `OFF`
  - never scan, even if a scanner object is registered;
- `WHEN_CONFIGURED` — **default**
  - if no scanner is configured, continue without scanning;
  - if a scanner is configured, scan and enforce the verdict;
- `REQUIRED`
  - scanner must be configured;
  - backend/scanner failure rejects the upload;
  - only explicit `CLEAN` permits the upload.

Remove the legacy `setRequireMalwareScan()` API.

Expose scanner status through `UploadProcessor::getInfo()`:

- scan mode;
- scanner configured yes/no;
- scanner provider identifier where available.

### 7.2 Native ClamAV daemon adapter

Ship a first-party `ClamAvDaemonScanner` using the clamd **INSTREAM** protocol.

Requirements:

- Unix-socket and TCP endpoint support;
- bounded connection/read/write timeouts;
- bounded protocol response size;
- chunked streaming rather than loading the complete file into memory;
- configurable maximum scanner-stream bytes with fail-closed behavior;
- stable `MalwareScannerException` errors;
- no shell execution;
- no requirement for the PHP worker to read arbitrary host files because Pathwise streams the private scan copy itself;
- no requirement for PHP/root privilege solely for scanning.

### 7.3 LMD / Linux Malware Detect integration

Preferred production deployment:

**Pathwise -> ClamAvDaemonScanner -> clamd with LMD signature integration enabled on the host.**

Pathwise will document LMD as an additional host-side malware-signature source rather than spawning `maldet` from web/request workers.

Rules:

- do not require the PHP worker to run as root;
- do not invoke `maldet` through `sudo` from Pathwise;
- do not couple the library to LMD filesystem paths, quarantine directories, or host-service lifecycle;
- applications may still implement a custom direct-LMD scanner through `MalwareScannerInterface` for specialized asynchronous/privileged deployments;
- direct host-level scanners should run out-of-process under the administrator's privilege model.

### 7.4 Other malware engines

AMWScan, ICAP, commercial scanners, cloud malware services, and custom engines remain pluggable through `MalwareScannerInterface`.

Do not add vendor-specific dependencies to Pathwise core unless the adapter is lightweight, stable, optional, and provides clear value comparable to the ClamAV protocol adapter.

### 7.5 Scanner acceptance tests

Must cover:

- OFF + scanner configured -> scanner not invoked;
- WHEN_CONFIGURED + no scanner -> upload proceeds without scanning;
- WHEN_CONFIGURED + scanner -> scan enforced;
- REQUIRED + no scanner -> fail before MIME/content parsing;
- all non-clean verdicts -> reject;
- backend errors -> reject without leaking backend detail;
- scan runs after cheap size/extension gates but before MIME/signature/image parsing;
- private local staging permissions and cleanup;
- remote/mounted source -> local scan copy;
- scanner mutation/replacement -> reject;
- chunk-finalization scan;
- ClamAV clean/found/error/unknown/oversize/timeout/protocol-malformation behavior using deterministic fake socket servers in tests.

---

## Batch 8 — security defaults and truthful filesystem guarantees

### 8.1 Policy engine deny-by-default

`PolicyEngine` must default to deny when no rule matches.

Allow an explicit default decision only when the caller opts into it intentionally.

Acceptance:

- empty policy denies;
- explicit allow passes;
- explicit deny blocks;
- last matching rule semantics remain deterministic;
- context predicates cannot accidentally convert an unmatched request into allow.

### 8.2 Atomic write semantics

Local atomic mode:

- same-directory temp file;
- final `rename()` must succeed atomically;
- **no copy fallback** when an atomic rename fails;
- failure leaves the previous destination intact wherever the OS/filesystem semantics permit;
- temp cleanup remains deterministic.

Adapter-backed / mounted / remote storage:

- `enableAtomicWrite()` must reject storage where Pathwise cannot guarantee an atomic filesystem replacement;
- normal non-atomic staged writes remain supported;
- docs must call these staged/synchronized writes, not atomic writes.

### 8.3 Permissions

Review every Pathwise-created local state file/directory:

- scanner staging;
- upload staging;
- queues;
- audit logs;
- transaction journals;
- native-command temporary outputs;
- indexes/snapshots where Pathwise creates them.

Use private defaults (`0700` directory / `0600` sensitive state file) where state is not explicitly intended for shared/public access.

---

## Batch 9 — bounded native execution

Current native command execution must not be able to pin a persistent worker indefinitely or accumulate unlimited output.

Introduce explicit execution limits:

- process timeout/deadline;
- stdout byte limit;
- stderr byte limit;
- polling without deadlock on filled pipes;
- TERM then bounded KILL/escalation where supported;
- deterministic descriptor/process cleanup;
- typed timeout/output-limit failures;
- preserve argv-array execution; never construct shell command strings from user input.

Apply limits to every native adapter path (`rsync`, native copy/move, zip tools, etc.).

Do **not** implement LMD scanning by routing `maldet` through this request-path runner; LMD integration remains host/service-oriented as defined in Batch 7.

Acceptance:

- hung child is terminated within configured bound;
- infinite/large stdout cannot exhaust memory;
- infinite/large stderr cannot exhaust memory;
- stdout+stderr cannot deadlock the parent;
- success result remains typed;
- exit-code failures remain typed;
- paths containing whitespace, Unicode, quotes, and shell metacharacters remain safe.

---

## Batch 10 — file queue lease correctness and durability

Current reservation ownership must be upgraded from `reservedAt`-only semantics to unique leases.

Introduce a unique reservation/lease token per successful reservation.

Requirements:

- acknowledgement/removal must require the current lease token, not job ID alone;
- retry/release must verify lease ownership;
- an expired lease reclaimed by worker B cannot be acknowledged by stale worker A;
- queue persistence uses crash-safe replacement;
- queue/lock files use private permissions;
- corrupt/truncated queue state fails explicitly rather than silently losing jobs;
- lock ownership and state-file replacement work on Linux and Windows;
- recovery behavior after interrupted writes is tested.

Acceptance includes deterministic tests for:

1. worker A reserves;
2. A lease expires;
3. worker B reclaims;
4. A attempts stale ack/release -> rejected;
5. B can complete safely.

---

## Batch 11 — archive, compression, metadata, and parser hardening

Full audit of all parser-like boundaries.

### Archives

Revalidate:

- traversal;
- absolute paths;
- Windows drive paths;
- UNC paths;
- symlink/hardlink archive entries;
- duplicate/conflicting entries;
- entry-count limit;
- per-entry uncompressed-size limit;
- total expanded-size limit;
- compression-ratio / zip-bomb defense;
- destination containment after filesystem normalization;
- cleanup on partial extraction failure.

### Metadata / image / format inspection

- keep malware scan before deeper parsing where scanning is active;
- bound bytes read for signature inspection;
- avoid parsing more data than required;
- ensure parser exceptions become stable Pathwise exceptions;
- temporary inspection copies use private permissions and guaranteed cleanup.

### Serialization

- Pathwise must never unserialize attacker-controlled input as part of validation;
- serialization helpers must continue rejecting unsafe object/resource values where appropriate;
- documentation must clearly distinguish serialization convenience from a safe untrusted-data format.

---

## Batch 12 — storage/facade/global-state cleanup for the major

Re-scan the static `PathwiseFacade`, `FlysystemHelper`, and `StorageFactory` APIs now that `StorageContext` exists.

Goals:

- `StorageContext` is the recommended persistent-runtime integration API;
- remove or de-emphasize redundant global mutable APIs when their only purpose is legacy compatibility;
- avoid two competing ways to implement the same multi-app storage registry;
- keep lightweight stateless convenience helpers where they remain useful;
- keep optional Flysystem adapters optional;
- ensure all adapter capability failures are explicit and typed.

Because 4.0 may break compatibility, removal is preferable to retaining unsafe duplicate global state solely for BC.

---

## Batch 13 — observability, audit, retention, indexing, watcher review

Audit every remaining subsystem not covered above:

- `AuditTrail` and sinks;
- local JSONL audit file locking/durability/permissions;
- partitioned audit paths and path containment;
- retention dry-run/delete consistency;
- checksum index concurrency and corruption behavior;
- snapshot/diff behavior;
- file watchers and polling bounds;
- directory synchronization reports;
- deduplication semantics and hash-algorithm defaults;
- transaction journals and rollback behavior.

Requirements:

- no silent data corruption;
- no unbounded in-memory growth on normal large-directory workflows where streaming is viable;
- no unsafe default path creation;
- persistent local state is private by default unless explicitly public/shared;
- typed results/exceptions for ambiguous operational failures.

---

# Documentation rebuild

## Batch 14 — complete Pathwise 4 documentation

Documentation is a release blocker.

The final docs must cover **every public feature** with realistic working examples, not just API lists.

### Required documentation structure

1. Installation and requirements
2. Architecture / when to use Pathwise
3. StorageContext
   - local disk
   - memory/custom adapter
   - S3/FTP/SFTP examples with optional packages
   - custom driver factory
   - logical path resolution
   - local-path capability
4. Static/convenience APIs that remain in 4.0
5. File operations
6. SafeFileReader
7. SafeFileWriter
   - normal writes
   - append
   - locking
   - local atomic replacement
   - remote staged writes and guarantee differences
8. Directory operations
   - copy/move/sync
   - native strategy
9. Native execution and safety limits
10. Upload processing
    - `UploadSource::fromPath`
    - stream input
    - framework mover example
    - HTTP `$_FILES` path where retained
    - validation profiles
    - extension/MIME/signature policy
    - image dimensions
    - naming/deduplication
11. Malware scanning
    - modes (`OFF`, `WHEN_CONFIGURED`, `REQUIRED`)
    - status introspection
    - custom scanner implementation
    - ClamAV Unix socket example
    - ClamAV TCP example with network-security warning
    - **LMD + ClamAV recommended Linux deployment**
    - direct LMD/custom asynchronous integration guidance
    - AMWScan/ICAP/custom examples
    - failure/verdict semantics
12. Chunked/resumable uploads
13. Download preparation and streaming
    - ranges
    - iterable integration
    - early-disposal/resource behavior
14. Safe symlink management
15. Compression/archive creation and secure extraction
16. File queue
    - lease token lifecycle
    - retry/expiry/stale-worker example
17. PolicyEngine
    - deny-by-default behavior
18. Audit/observability
19. Retention
20. Checksum indexing/deduplication
21. File watcher
22. Permissions/ownership helpers
23. Transactions/journals/rollback
24. Error/exception reference
25. Security model and threat boundaries
26. Performance guidance and large-file considerations
27. Migration guide from Pathwise 3.x to 4.0
28. Complete runnable recipes
29. Optional-adapter package matrix
30. Foundation integration notes that explain ownership boundaries without coupling Pathwise to Foundation.

### Documentation quality gate

- every public class/mode/result has a discoverable docs entry;
- every complex feature has at least one complete working example;
- examples match actual 4.0 method signatures;
- examples are syntax-checked where practical;
- ReadTheDocs/Sphinx build is warning-free;
- README links to the complete docs rather than trying to duplicate them.

---

# Performance and release acceptance

## Batch 15 — performance, stress, and release gates

Benchmarks are intentionally deferred until the functional/security batches are stable, but they remain mandatory before 4.0 release.

Extend the existing PHPBench/stress suite rather than creating a parallel benchmark framework.

Measure at minimum:

- `StorageContext` hot lookup/path resolution;
- upload source materialization;
- malware-scan staging overhead without measuring external AV engine speed as a Pathwise regression;
- download chunk iteration/range positioning;
- queue reserve/ack/retry under contention;
- local atomic writer;
- remote staged writer;
- large archive validation/extraction guard overhead;
- native runner bounded-process overhead;
- checksum/index/dedup large-directory workflows.

Release gates:

- PHP 8.4 stable + lowest ✅
- PHP 8.5 stable + lowest ✅
- PHPStan/Psalm on supported PHP versions ✅
- PHPCS/Pint/Rector/Deptrac/PHPProbe/PHPForge gates ✅
- clean `composer install --no-dev` ✅
- optional adapter contract suite ✅
- Windows PHP 8.4/8.5 relevant filesystem tests ✅
- documentation build ✅
- security regression suite ✅
- concurrency/lease regression suite ✅
- stress/benchmark review ✅
- no unresolved PR code-scanning/review findings ✅

---

# Foundation 3 consumption after Pathwise 4 release

Foundation should then:

- raise its Pathwise floor to `^4.0`;
- compose `StorageContext` from Foundation filesystem config;
- remove Foundation's process-global Pathwise mount-scope workaround;
- build `UploadSource` directly from Webrick uploaded-file abstractions;
- remove Foundation-owned upload temp-file materialization;
- choose `MalwareScanMode` as application policy;
- inject a configured scanner (`ClamAvDaemonScanner` or another `MalwareScannerInterface`);
- fail at Foundation build/boot time when policy requires scanning but no scanner is configured, while Pathwise remains the runtime fail-closed backstop;
- consume Pathwise range-aware iterable streaming instead of duplicating seek/discard/read mechanics;
- use Pathwise `SafeSymlinkManager` behind Foundation's application-root/config policy;
- keep Webrick HTTP conditional/offload response semantics in Foundation/Webrick;
- retain `PathManager` in Foundation because application directory semantics are Foundation-owned.

---

# Work order from this point

1. **Batch 7** — finish scan modes, ClamAV adapter, LMD integration docs/tests.
2. **Batch 8** — policy default + atomic semantics + private-state permissions.
3. **Batch 9** — bounded native execution.
4. **Batch 10** — queue lease/durability repair.
5. **Batch 11** — archive/parser hardening.
6. **Batch 12** — global/static API cleanup.
7. **Batch 13** — remaining subsystem audit/hardening.
8. **Batch 14** — complete documentation rebuild and migration guide.
9. **Batch 15** — benchmarks/stress/final release gates.
10. Release Pathwise 4.0, then complete Foundation Point 26.5 against the released floor.

## Push discipline

Each batch must be implementation-complete and locally/repository-CI validated before it is marked complete. Push/closure commits should stay batch-scoped so regressions remain attributable.
