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

Current active batch: **Batch 12 — storage/facade/global-state cleanup**.

| Area | Status | Tracking note |
| --- | --- | --- |
| Plan freeze / Pathwise 4 scope | [X] | Major-release scope, ownership rules, work order, documentation gate, and release gates are frozen in this file. |
| Batch 1 — instance-scoped storage context | [X] | `StorageContext` and per-instance storage/driver resolution are implemented. |
| Batch 2 — typed upload-source ownership | [X] | `UploadSource` materialization and cleanup ownership are implemented. |
| Batch 3 — hardened malware boundary | [X] | Typed request/verdict contract, private staging, fail-closed verdicts, mutation detection, and chunk-finalization scanning are implemented. |
| Batch 4 — prepared range-aware download streaming | [X] | Range-aware iterable streaming and deterministic resource closure are implemented. |
| Batch 5 — generic safe symlink management | [X] | Generic root-contained link create/status/remove behavior is implemented. |
| Batch 6 — production PSR-3 dependency | [X] | `psr/log ^3.0.2` is a direct production dependency. |
| Batch 7.1 — malware scan modes/status | [X] | `OFF`, `WHEN_CONFIGURED`, and `REQUIRED`, typed provider diagnostics, and status introspection are implemented and accepted. |
| Batch 7.2 — ClamAV daemon adapter | [X] | Bounded INSTREAM adapter, Unix/TCP endpoints, stream/response limits, timeout handling, and protocol behavior are implemented and CI-green. |
| Batch 7.3 — LMD integration guidance | [X] | Preferred ClamAV + LMD host deployment and no-root/no-sudo request-worker guidance are documented. |
| Batch 7.4 — other scanner engines | [X] | The generic typed scanner contract and custom/other-engine integration guidance are complete without vendor coupling. |
| Batch 7.5 — scanner acceptance suite | [X] | Malware-mode, staging, mutation, chunk-finalization, and deterministic fake-clamd protocol/error/timeout/limit coverage pass all required CI gates. |
| Batch 8.1 — deny-by-default policy engine | [X] | Empty policies deny, explicit permissive default remains opt-in, and deterministic last-match semantics are covered. |
| Batch 8.2 — truthful atomic-write semantics | [X] | Local same-directory rename is the only atomic guarantee; adapter-backed atomic mode is rejected and staged remote writes remain explicitly non-atomic. |
| Batch 8.3 — private Pathwise-owned local state | [X] | Scanner/upload staging, queue state, local JSONL audit state, and partitioned audit objects use private defaults where Pathwise owns creation; POSIX permission acceptance and cross-platform semantics are CI-green. |
| Batch 8 — security defaults / atomic guarantees / permissions | [X] | All three sub-batches are complete; acceptance passed Windows PHP 8.4/8.5, optional adapters, stable+lowest QA, PHPStan/Psalm, and clean install. |
| Batch 9 — bounded native execution | [X] | Bounded non-blocking native execution, timeout/output caps, typed failures, deterministic termination/cleanup, argv-only invocation, adapter-wide limits, and capability-based Windows fallback are CI-green. |
| Batch 10 — file queue lease correctness/durability | [X] | Typed unique leases, expiry/renewal ownership checks, stale-worker rejection, versioned strict state, stable private lock file, crash-safe fsync+rename persistence, corruption handling, and recovery tests are CI-green on Linux and Windows. |
| Batch 11 — archive/parser hardening | [X] | Unified manifest-based ZIP validation/extraction, collision and special-entry rejection, streamed byte enforcement, write-time revalidation, deterministic local/remote cleanup, source-symlink rejection, safe serialization boundaries, and parser regressions are CI-green across the full matrix. |
| Batch 12 — static/global-state cleanup | [~] | Active: remove redundant process-global storage registry/facade APIs and make `StorageContext` the persistent-runtime integration surface. |
| Batch 13 — observability/retention/indexing/watcher review | [ ] | Pending whole-library subsystem audit. |
| Batch 14 — complete Pathwise 4 documentation | [ ] | Release blocker; starts after public APIs are stable, with feature docs added earlier when useful. |
| Batch 15 — performance/stress/release gates | [ ] | Final acceptance only after functional/security batches stabilize. |
| Pathwise 4.0 release | [!] | Blocked until Batches 12–15 and all release gates pass. |
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
9. **Platform support is capability-based, not emulation-based.** Do not create parallel OS-specific engines merely to imitate unavailable guarantees. `AUTO` must use the portable Pathwise/PHP implementation when a native capability is unavailable; forced `NATIVE` must fail explicitly and typed.

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

## Batch 3 — hardened malware boundary — complete, extended for 4.0

- typed `MalwareScannerInterface` and `MalwareScanRequest`;
- explicit `MalwareScanVerdict` and fail-closed enforcement;
- scan before MIME/signature/image parsing;
- private local scan copies and deterministic cleanup;
- scanner/backend errors mapped to stable upload errors;
- scan-input mutation detection;
- actual size enforcement;
- mounted/remote localization;
- full assembled chunk scan before publication.

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

## Batch 7 — malware policy modes and scanner providers — complete

### 7.1 Explicit malware scan modes

`MalwareScanMode` provides `OFF`, `WHEN_CONFIGURED` (default), and `REQUIRED`. Scanner status is exposed through upload processor information, legacy required-scanner boolean behavior is removed, and only explicit `CLEAN` permits scanned uploads.

### 7.2 Native ClamAV daemon adapter

`ClamAvDaemonScanner` supports bounded clamd INSTREAM scanning over Unix sockets and TCP with bounded connection/read/write timeouts, response size, streamed bytes, stable scanner exceptions, and no shell/root requirement.

### 7.3 LMD / Linux Malware Detect integration

Preferred production deployment is **Pathwise -> ClamAvDaemonScanner -> clamd with LMD signature integration enabled on the host**. Pathwise does not require root, sudo, host LMD paths, or request-worker `maldet` execution. Specialized direct LMD integrations remain application-owned implementations of `MalwareScannerInterface`.

### 7.4 Other malware engines

AMWScan, ICAP, commercial scanners, cloud malware services, and custom engines remain pluggable through `MalwareScannerInterface` without vendor coupling in core.

### 7.5 Scanner acceptance — complete

Mode behavior, all non-clean verdicts, backend errors, pre-parser scan ordering, private staging, mutation/replacement, chunk finalization, and deterministic clamd clean/found/error/unknown/oversize/timeout/protocol coverage are CI-green.

---

## Batch 8 — security defaults and truthful filesystem guarantees — complete

### 8.1 Policy engine deny-by-default

Empty/unmatched policies deny unless the caller intentionally supplies an explicit default decision. Matching semantics remain deterministic.

### 8.2 Atomic write semantics

Local atomic mode uses same-directory staging and requires final rename success with no copy fallback. Adapter-backed/remote storage cannot opt into a guarantee Pathwise cannot provide; normal staged remote writes remain explicitly non-atomic.

### 8.3 Permissions

Pathwise-owned sensitive local staging/state uses private `0700` directories and `0600` files where the platform exposes POSIX permissions.

---

## Batch 9 — bounded native execution — complete

Native execution has process deadlines, stdout/stderr caps, non-blocking pipe handling, bounded termination escalation, deterministic cleanup, typed start/timeout/output/exit/unsupported failures, and argv-only invocation. Native adapters (`rsync`, `cp`, `grep`, `zip`, `unzip`) use the bounded runner. Unsupported platforms/capabilities fall back in `AUTO` and fail explicitly in forced `NATIVE` mode.

---

## Batch 10 — file queue lease correctness and durability — complete

The file queue uses typed unique lease ownership, expiry/renew/ack/release/fail semantics, stale-worker rejection, versioned strict state, a stable private lock file, crash-safe same-directory temp + `fflush()`/`fsync()` + rename persistence, deterministic orphan cleanup, and corruption/version/duplicate fail-closed behavior.

---

## Batch 11 — archive, compression, metadata, and parser hardening — complete

### Archives

Implemented:

- traversal, null-byte, Unix absolute, Windows drive/UNC rejection;
- duplicate/canonical/case-fold and file/directory collision rejection before writes;
- symbolic-link and unsupported Unix special-file entry rejection;
- entry-count, per-entry size, total expanded-size, and compression-ratio limits;
- validated manifest entries carrying expected uncompressed bytes;
- actual streamed-byte enforcement during extraction;
- containment and destination-symlink revalidation immediately before publication;
- one security-critical validator/extractor shared by `FileCompression`, selective extraction, and `DirectoryOperations::unzip()`;
- deterministic transactional rollback for local partial extraction;
- private local staging and rollback for adapter-backed publication without overwriting unrelated pre-existing remote content;
- hardened native extraction explicitly unavailable when Pathwise cannot preserve byte/rollback guarantees;
- archive creation rejects source symlinks rather than following out-of-root content.

### Metadata / image / format inspection

The upload pipeline preserves cheap size/extension gates followed by malware scanning when active before deeper MIME/signature/image inspection. Signature reads remain bounded to configured signature requirements; parser/backend errors are normalized through Pathwise exceptions and Pathwise-owned localization remains private and cleaned.

### Serialization

- Pathwise does not use PHP deserialization for upload/archive/metadata trust decisions;
- `SafeFileReader` deserializes with `allowed_classes => false`;
- decoded object/resource-like values are rejected by the safe-value validator;
- malformed/unsafe payloads become stable `FileAccessException` failures;
- PHP serialization is treated as a trusted-data convenience, not an untrusted interchange format.

### Batch 11 acceptance — passed

Regression coverage includes archive collisions, special entries, size/ratio limits, write-time symlink/containment checks, local rollback, selected-entry validation, source-symlink behavior, explicit native-extraction rejection, and safe serialization. The final Batch 11 tree passed Windows PHP 8.4/8.5, optional adapters, PHP 8.4/8.5 stable+lowest QA, PHPStan/Psalm, and clean-install gates in Security & Standards run **#139**.

---

## Batch 12 — storage/facade/global-state cleanup for the major — active

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

1. **Batch 12** — storage/facade/global-state cleanup.
2. **Batch 13** — remaining subsystem audit/hardening.
3. **Batch 14** — complete documentation rebuild and migration guide.
4. **Batch 15** — benchmarks/stress/final release gates.
5. Release Pathwise 4.0, then complete Foundation Point 26.5 against the released floor.

## Push discipline

Each batch must be implementation-complete and locally/repository-CI validated before it is marked complete. Push/closure commits should stay batch-scoped so regressions remain attributable.
