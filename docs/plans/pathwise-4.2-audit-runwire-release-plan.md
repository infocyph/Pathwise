# Pathwise 4.2.0 audit, remediation and Runwire release plan

Audit date: 2026-10-09, Asia/Dhaka. Status: proposed; production fixes and integration are not implemented by this document.

Audited Pathwise revision: `eead7cc6a1ac498a602f7f0ac0b3ff316ea979d9`, tagged `4.1`.
Runwire API reference: exact tag `2.1.1`, commit `745b1c2bd7caa56aa5abf6d742c1aa8318fec494`.

## Decision and governing constraints

Target a single final release: **4.2.0**, containing the required security, data-integrity, correctness and quality corrections together with additive, explicitly passed Runwire context support. Runwire remains optional for consumers; implementing and validating that optional integration is part of this release's completion gate. Remediation and integration are implementation batches within the same release, with no intermediate patch release planned. A major release is unnecessary for this scope. Preserve existing public contracts, serialized formats, native APIs and the PHP baseline so the target remains backward compatible.

This plan follows [PHPForge engineering principles](../../vendor/infocyph/phpforge/resources/engineering-principles.md), resolved from the repository root as `vendor/infocyph/phpforge/resources/engineering-principles.md`. Correctness, security, data integrity, compatibility and operational stability precede throughput. Prefer fixes in existing cohesive owners, shared existing validation and private helpers over additional layers. Preserve public parameter names and extension contracts. Do not raise thresholds, add baselines, suppress findings, exclude affected code, weaken tests, or skip release gates.

Keep required remediation, consumer-optional Runwire integration and deferred optimization distinct within the single release. Measure performance-sensitive changes using representative sustained successful host RPM; component benchmarks are supporting evidence. Prefer the simpler implementation when measured results are practically equivalent.

The [4.1 trust-boundary plan](https://github.com/infocyph/Pathwise/blob/4.1/docs/plans/pathwise-4.1-upload-filesystem-trust-boundary-plan.md) remains the reference for filesystem ownership. The proposed 4.2 integration deliberately revises its prohibition on public Runwire types at an optional boundary. Host ownership of workers, event loops, requests, cancellation sources and task scopes remains intact. Generic subprocess orchestration stays outside Pathwise's filesystem responsibilities.

## Audit coverage and current evidence

Reviewed uploads and staging ownership, local and mounted storage routing, publication and permissions, download/public-file resolution and ranges, archive creation/extraction, safe readers/writers and serialization, queue leases/failure transitions, checksum deduplication, filesystem watchers, policy/path helpers, native execution, malware scanners, optional adapters, persistent-state isolation, dependencies, documentation and CI. The updated Graphify graph was used for navigation; source inspection and executable probes establish findings.

| Check | Current result | Meaning |
| --- | --- | --- |
| `composer validate --strict` | Pass | Current manifest validates. |
| PHPForge doctor/config discovery | Completed | Used the installed project tool configuration. |
| `composer ic:tests:details` | Fails overall | Twelve PHPStan complexity findings; initial fake-clamd tests could not bind in the sandbox. |
| `composer ic:test:code`, with local socket access | **400 passed, 1,311 assertions** | All six initial socket failures were environmental. Existing tests miss the findings below. |
| Other aggregate checks | Pass | Syntax/references, comments, skip scanner, normalization, Pint, PHPCS, Psalm, Deptrac and Rector dry run completed successfully. |
| Duplicate detector | Pass with **8 groups, 282 lines, 1.55%** | Reported groups still require semantic triage and meaningful consolidation. |
| `composer audit --locked --format=json`, with network access | No advisories; exits 1 for abandonment | `doctrine/annotations` is abandoned and enters through development-only `phpbench/phpbench`; this is not a clean audit exit. |
| `composer benchmark:release` | **16 subjects completed** | One iteration/revolution each; no sustained RPM or statistically reliable before/after claim. |
| Hosted QA for the audited SHA | Pass | [Run 37175000817](https://github.com/infocyph/Pathwise/actions/runs/37175000817), 2026-10-04: all 15 jobs, including Windows/PHP 8.4/8.5, release stress, optional adapters, analysis, benchmarks and dependency matrices. |

Local runtime was PHP 8.5.4 and Composer 2.10.3. The older green hosted run does not certify today's installed analyzer configuration or future candidate changes. No Foundation 3 composition or passed-Runwire chain was certified in this audit. A dependency advisory scan cannot establish absence of application-level vulnerabilities.

The following priorities describe engineering impact under the stated preconditions; they are not CVSS ratings or claims of exploitation in deployed applications. Linux findings were exercised with controlled temporary fixtures. Windows filename handling is a verified validation gap requiring native Windows confirmation.

## Required findings and remediation

### P01 — High: local storage containment denial becomes upload fallback

**Owner:** `StorageContextRoutingConcern::storageDirectLocalPath()`, `UploadPublicationConcern`, `StorageContext`.

**Evidence:** Create a local disk root containing `uploads -> outside-directory`; `StorageContext::localPath('primary://uploads')` rejects it. Configure an `UNTRUSTED_DATA` uploader for the same mounted directory and ingest a small text source. Upload succeeds and writes the resulting file in the outside directory. The routing helper catches every `InvalidArgumentException`, returns `null`, and publication treats the denial as a non-local adapter route. This also avoids the direct-local publication safeguards. An existing link is sufficient; no concurrent replacement was required.

**Fix:** Determine local-backend capability independently from resolving and validating its path. Propagate an invalid local path; never turn a containment/security rejection into adapter fallback. Apply the same local publication and private-permission guarantees to local mounted disks. Review every caller of the helper for the same classification error.

**Regression:** Extend `StorageContextProcessorTest` and `UploadTrustBoundaryTest` with an escaping link at the upload root and below it, default and named local disks, legitimate local paths, and genuinely remote adapters. Denial must leave the outside directory and staging state untouched. Remote capability fallback must remain functional.

### P02 — High: upload limits apply after unbounded materialization

**Owner:** `UploadSource::materialize()` / `copyStreamToTarget()`, `UploadProcessor`, `UploadProcessorChunkConcern`.

**Evidence:** With a 16-byte upload limit, a stream declaring one byte but supplying 1 MiB is fully consumed before `FileSizeExceededException`. `stream_copy_to_stream()` has no byte ceiling. Chunk limits bound each chunk and count, but do not impose the final upload limit on cumulative persisted bytes before merge; the merged payload is checked afterwards. This permits excessive temporary storage, hashing and I/O before eventual rejection.

**Fix:** Carry the effective finite byte limit into library-controlled materialization, count actual copied bytes, and stop with bounded over-read. Advisory sizes may reject early but cannot authorize actual excess. Enforce cumulative session size under the existing chunk lock, including replacement/retry accounting, before persistence and merge. Reject unlimited effective upload limits in the strict untrusted profile. Keep the trusted profile's explicit compatibility choices documented.

**Boundary:** Arbitrary caller-supplied mover callbacks cannot be interrupted or storage-limited by checking their return value. Precheck known sizes, validate the resulting file, and document host callback/quota ownership; do not promise a hard streaming limit for arbitrary movers. Borrowed streams remain open, unsuccessful materialization cleans owned staging, and owned-source consumption occurs only after successful staging.

**Regression:** Extend `UploadSourceTest`, `UploadOwnedStagingTest` and `UploadProcessorTest`: understated/unknown sizes, exact limit, limit plus one, large streams with measured bounded consumption, aggregate chunks, duplicate chunk retries, cleanup and strict-limit configuration. Verify the bound on bytes read/written, not only the final exception.

### P03 — High: compact serialized reference graphs cause exponential validation

**Owner:** `SerializedValueValidator`, `SafeFileReader`, `SafeFileWriterWriteConcern::isSafeSerializedValue()`.

**Evidence:** Build a leaf array, then repeatedly make an array containing two references to the previous layer. Twenty layers serialize to **393 bytes**, yet the validator revisits the shared graph over two million times, taking approximately 386 ms in the probe. More layers multiply the work while remaining far below the depth limit. Reader and writer both contain this recursion pattern. `allowed_classes => false` prevents object construction, but does not bound traversal work.

**Fix:** Reuse the existing validator for reader and writer with a finite total-node/work budget, independently bounded depth and serialized input size. Reject excessive graphs deterministically; reference-aware traversal is optional if it actually reduces total complexity and preserves rejection semantics. Keep rejection of objects, resources, invalid floats and unsafe cycles. Native unserialize depth limits alone do not solve repeated traversal.

**Regression:** Extend `SafeFileReaderTest` and `SafeFileWriterTest` with ordinary round trips, aliases, cycles and the compact doubling graph. Use a deterministic visit budget as the assertion, with a generous runtime guard as supporting evidence. Do not execute arbitrarily deep exponential probes.

### P04 — High: native ZIP creation leaks linked content

**Owner:** `DirectoryOperationsZipConcern::tryNativeZip()`, `FileCompression`, `NativeOperationsAdapter::compressToZip()`.

**Evidence:** A source directory contains `linked.txt` pointing to a private fixture outside the source root. PHP archive creation rejects the link. AUTO creation runs `zip -q -r ... .` first and succeeds; `linked.txt` contains the outside fixture bytes in the archive. `DirectoryOperations` defaults to AUTO, so the difference affects the normal directory ZIP path. `FileCompression` exposes the same native path when selected.

**Fix:** Enforce the source-tree/no-link contract before selecting native creation. Native and PHP strategies must produce equivalent security decisions. If a native utility cannot preserve the contract, reject NATIVE or use the validated PHP path for AUTO. Merely switching to `zip -y` changes semantics and is not equivalent to rejecting links. Preserve the existing guarded PHP extraction path for strict archives.

**Regression:** Extend `ArchiveSecurityTest`, `DirectoryOperationsTest` and `NativeExecutionTest` across PHP/AUTO/NATIVE, file and directory links, outside and inside targets, and absent native utilities. No forbidden content may appear in an archive. Document the residual concurrent-mutation boundary; a preflight walk alone is not proof against hostile changes during native execution.

### P05 — High data integrity: writer lock truncates before ownership

**Owner:** `SafeFileWriter::lock()` and writer opening/initialization.

**Evidence:** One handle holds an exclusive lock on an existing file. A second default writer calls nonblocking `lock()`, fails to acquire it, but the original contents are already empty because it opened with mode `w`. Separately, `waitForLock=true`, one retry and a 1 ms delay still waits about 250 ms until the owner releases the lock: it calls blocking `flock`, bypassing the advertised retry bound.

**Fix:** Open without truncation before lock acquisition; perform the intended non-append truncation only after obtaining ownership at the defined write boundary. Use nonblocking acquisition for bounded retries, validate retry/delay inputs, and apply a monotonic wait budget. Retain append and explicit truncation contracts. Clarify that locks on atomic writer temporary files do not coordinate separate writers to the final pathname.

**Regression:** Extend `SafeFileWriterTest` with competing processes, unchanged bytes on failed acquisition, bounded waits, successful append/overwrite, and atomic publication behavior. Check behavior on supported Windows versions as well as Linux.

### P06 — Medium: ClamAV endpoint and operation bounds are incomplete

**Owner:** `ClamAvDaemonScanner`.

**Evidence:** Construction accepts `tcp://127.attacker.example:3310` and `tcp://127.0.0.1.attacker.example:3310` with `allowRemoteTcp=false`, because the host check accepts the string prefix `127.`. No connection to those hosts was attempted. Remote resolution would bypass the intended loopback policy. Both `NAN` and `INF` timeout values are accepted. Per-read/write timeouts do not provide a whole-scan deadline against slow progress; the streaming limit checks request metadata instead of counting transmitted bytes.

**Fix:** Validate actual loopback IP literals (`127.0.0.0/8`, `::1`); handle `localhost` through a pinned/verified loopback connection rather than arbitrary hostname trust. Keep remote TCP explicit. Require finite, positive and representable timeouts. Add a finite monotonic operation deadline and count actual streamed bytes. Preserve bounded response parsing, fail-closed verdicts, and resource cleanup on connect, read, write and cancellation failure.

**Regression:** Extend `ClamAvDaemonScannerTest` with deceptive hostnames, valid loopback forms, explicit remote opt-in, invalid float limits, metadata/stream mismatch and slow-progress fake daemons. No real external daemon is required.

### P07 — Medium validation gap: Windows archive pathname semantics

**Owner:** `ZipEntryValidator`; review `PublicFileResolver` and `StorageContext` at their Windows local-path boundaries.

**Evidence:** ZIP validation on Linux accepts `file.txt:payload`, `CON.txt`, `safe/.. /escape.txt`, and `file.txt.`. Windows reserves device names even with extensions, uses colon for stream syntax, and has trailing-dot/space filename rules. See [Microsoft's filename rules](https://learn.microsoft.com/en-us/windows/win32/fileio/naming-a-file). The current Windows CI does not establish safety for these adversarial entries. Actual ADS/device/path-alias exploitation has not been reproduced on NTFS in this audit.

**Fix:** Define and enforce platform-aware Windows segment validation and canonical collision keys, or a documented portable strict policy where that is the existing contract. Reject ADS, reserved device segments and unsafe trailing characters on Windows before opening paths. Do not globally reject legitimate POSIX names as an incidental compatibility change. Apply shared boundary validation only where the same contract is required.

**Regression:** Add NTFS extraction and public-path probes to the existing Windows/PHP 8.4/8.5 matrix: no ADS creation, device access, escape, overwrite or alias collision. Include legitimate Windows names and Linux behavior. Keep exploit claims conditional until that evidence exists.

### P08 — Medium: queue error encoding breaks the failure transition

**Owner:** `FileJobQueue::fail()`.

**Evidence:** A valid message consisting of 4,095 ASCII bytes followed by an emoji is cut by `substr(..., 0, 4096)` inside its UTF-8 sequence. JSON encoding then throws; the reservation remains processing rather than entering failed state. Handler failure reporting can itself abort processing.

**Fix:** Normalize error text to valid UTF-8 and impose a byte ceiling without splitting a code point. Handle invalid input bytes predictably without requiring a new runtime extension. Error-message sanitization must not compromise the lease-owned state transition or replace the original failure with an encoding error.

**Regression:** Extend `FileJobQueueTest`: boundary emoji, invalid UTF-8, ordinary and long exceptions, failure budgets and subsequent processing. Verify reservation ownership and final counts.

### P09 — Medium correctness: valid records are skipped or cannot round-trip

**Owner:** `SafeFileReader::jsonIteratorWithHandling()`, serialized reader/writer framing.

**Evidence:** JSON lines `0`, `false`, `null`, `1` return only the latter three because `if ($line)` discards string `"0"`. `writeSerialized("one\ntwo")` accepts and writes a value which the line-based `serializedValues()` reader cannot deserialize as a complete record.

**Fix:** Use explicit empty-line checks for JSON. For legacy serialized lines, reject unsupported line-breaking serialized payloads before writing and document the existing framing limit; do not silently change on-disk format. Defer an additive versioned framing API unless confirmed consumer requirements justify including it in 4.2.0, with legacy decoding preserved. Existing valid records and public format contracts must remain readable.

**Regression:** Extend reader/writer tests with scalar zero, whitespace, blank lines, null/false, strings and arrays containing line breaks, and existing serialized fixtures. Treat additive framing as separate optional feature work; do not hide an incompatible format migration in this release.

### P10 — Medium native operand handling: argv remains subject to options

**Owner:** `NativeOperationsAdapter`, fixed-command construction in callers.

**Evidence:** Copying a valid relative file named `-source.txt` invokes `cp -f -source.txt ...` and fails on option parsing. Shell-free argv prevents shell expansion, but does not make utility operands immune to option interpretation. Other utility-specific operand rules also need review; no arbitrary shell execution was demonstrated.

**Fix:** Use supported operand separators or validated absolute local operands as appropriate for each fixed utility. Review ZIP argument ordering and rsync's remote-host colon syntax separately. Preserve fixed executable selection, bounded output/deadlines, and PHP fallback for unavailable capabilities. Security denials and cancellation must not be converted into ordinary fallback.

**Regression:** Extend `NativeExecutionTest` and runner isolation tests with leading hyphens, spaces, metacharacters, colon-bearing local paths and existing destination/source policies. Verify actual argv and resulting files without invoking destructive options.

### P11 — Medium data integrity: deduplication accepts symlink canonical files

**Owner:** `ChecksumIndexer::isLocalFile()`, `deduplicateWithHardLinks()` and publication checks.

**Evidence:** Create two equal files, identify their duplicate-group ordering, replace the canonical entry with a link to an equal outside fixture, then start deduplication. The other regular file becomes a symlink too: `is_file()` follows the canonical link, while `link()` can hard-link the symlink inode. The fixture replacement preceded the operation; it was not a race during publication.

**Fix:** Reject symlink canonical and target candidates, verify regular-file identity at the relevant mutation boundaries, and preserve rollback. Keep byte comparison after hashes; hashes are grouping hints. Preserve intentional hard-link semantics and document shared-inode ownership/metadata effects.

**Regression:** Extend `ChecksumIndexerTest`: canonical link, target link, outside target, changed identity, equal regular files, no partial mutation and backup restoration. Do not claim race-proof publication from path checks alone.

### P12 — Required quality/toolchain/documentation work

Current PHPStan findings must be resolved with control-flow simplification in their existing owners:

| Owner | Current score | Limit |
| --- | --- | --- |
| `FileCompression::countFilesForCompression()` | 14 | 12 |
| `FileOperations::performCopy()` | 13 | 12 |
| `ChecksumIndexer::filesAreIdentical()` | 14 | 12 |
| `PolicyEngine::isAllowed()` | 13 | 12 |
| `ZipEntryValidator::validate()` | 14 | 12 |
| `ZipEntryValidator::assertNoPathConflict()` | 13 | 12 |
| `UploadProcessor::scanForMalware()` | 13 | 12 |
| `DownloadProcessor` class | 88 | 80 |
| `PublicFileResolver::resolve()` | 13 | 12 |
| `ClamAvDaemonScanner::readResponse()` | 17 | 12 |
| `FileWatcher::snapshot()` | 15 | 12 |
| `PathHelper` class | 81 | 80 |

Triage all eight clone groups: remote ZIP publication; extraction-limit validation; queue enqueue/upload manifest shapes; benchmark fixtures; queue fail/release lease removal; storage option normalization; permission handling; native runner cleanup. Centralize genuinely repeated logic in its existing owner or a justified shared boundary and update every caller. Similar statement shapes in unrelated queue/upload contracts are not permission to merge responsibilities; document their semantics and resolve any valid detector finding without hiding it.

The abandoned `doctrine/annotations` package belongs to the PHPBench/PHPForge development chain. Find an upstream-supported replacement/upgrade and verify the full toolchain; do not add an application runtime workaround, weaken audit policy, or falsely label a remaining abandonment report clean. Record an unresolved upstream dependency explicitly if it cannot yet be removed.

Update README's Flysystem constraint to match `composer.json`, release references and outdated Runwire 1.0 guidance. Keep deployment documentation, runnable examples and actual optional dependency requirements synchronized. Do not rewrite unrelated APIs or merge meaningful exceptions/results/contracts to reduce file count.

## 4.2.0 integration: consumer-optional borrowed Runwire context

### API and ownership

Use one optional `RunwireExecutionContext` holding the exact caller-supplied `RuntimeContext`, optional `RequestContext`, and optional `CoroutineScope`. Its independent existence is justified by validating a borrowed lifecycle and forwarding the same typed contract through unrelated consumers. It must not create a runtime, worker, event loop, request, scheduler, cancellation source or scope.

Prefer an additive scoped method on participating owners:

```php
// Proposed API; these classes/methods are not implemented yet.
$execution = new RunwireExecutionContext($runtime, $request, $scope);
$path = $uploader->withRunwire(
    $execution,
    static fn (UploadProcessor $bound): string => $bound->ingestSource($source),
);
```

An intermediary accepts the same `$execution` instance and passes it to Pathwise. The framework supplies its active request/task scope. Explicit forwarding is also required when the host starts another Fiber/task. Existing upload/download/archive method signatures stay unchanged: these classes are extensible, and simply adding optional parameters can break consumer overrides.

Implement the smallest shared scoped-binding mechanism, preferably within the context owner, with bindings keyed by both participating object and current Fiber/main execution. Restore nested bindings in `finally`; do not retain request references after return or implicitly inherit bindings across Fibers. Resolve the optional context once at operation entry and forward it through private loops. A second internal helper requires a concrete lifecycle/reuse justification. No global default runtime, service locator, registry of backends or new scheduler is required.

Configuration mutation on a shared processor remains subject to that processor's existing concurrency contract. Fiber-local context isolation does not make arbitrary mutable host objects safe for concurrent configuration changes.

Require exact Runwire **`2.1.1`** for development/integration fixtures and suggest it for optional production use. Do not make it a mandatory runtime dependency. Test a production-only installation with Runwire absent, normal operations and class loading; installing Runwire alone must activate nothing. Preserve existing public names, returns, exception policy and baseline PHP >=8.4.

### Capability decisions

| Supplied context/capability | Behavior |
| --- | --- |
| No execution context, or Runwire absent | Existing synchronous path. |
| Runtime with no applicable cooperative capability/scope | Synchronous operation; honor any valid passed cancellation/deadline at checkpoints. |
| Matching active request | Reject a completed request or runtime mismatch; honor its cancellation and remaining deadline. |
| Open scope plus `RUNWIRE_COROUTINES` in the supplied runtime | Bounded yielding and cooperative waits inside a host-scheduled task. Validate the live scope using Runwire's existing public guards. |
| Closed/stale/mismatched context, cancellation, timeout or local security denial | Fail explicitly; never silently retry via an unbound or weaker path. |

Use actual 2.1.1 APIs: `RequestContext::runtime()` / `completed()`, cancellation tokens, and scope guards. There is no public `RequestContext::isActive()` and no generic filesystem/process-runner capability flag. Runtime concurrency metadata or `RUNWIRE_LOOP_AVAILABLE` alone does not prove a live coroutine task. Calls requiring a scheduler must execute within the supplied host task; never catch a scheduler-lifecycle error and treat it as absent capability.

### Useful, bounded scope

Start with checkpointing in bounded upload/download copying, archive validation/extraction, checksumming/indexing and directory traversal loops. Use cooperative sleep for bounded nonblocking lock retries and watcher intervals when a live scope supports it. Check cancellation before irreversible publication, always perform owned cleanup, and return success after a completed commit rather than throwing a late cancellation that falsely reports failure.

Validate potential nonblocking clamd socket waiting separately using the existing scanner extension contract and a host-bound scanner instance carrying the same execution context. Do not modify every scanner interface signature or claim cooperative socket I/O until actual byte/deadline and integration fixtures pass. An opaque third-party Flysystem call, native archive subprocess, blocking disk call or arbitrary mover callback does not become asynchronous merely because a context is present; document those cancellation checkpoint boundaries.

Runwire 2.1.1 `ProcessRunner::run()` is synchronous and accepts no RuntimeContext, RequestContext, scope or cancellation token. Its presence does not provide cooperative or in-flight cancellable native execution. A host adapter may enforce a pre-execution checkpoint and clamp a command's existing finite timeout to the remaining deadline. Defer replacement of Pathwise's native runner unless measured benefit and equivalent filesystem/security contracts justify it; genuine in-flight cooperation would require a separate proven host adapter or upstream capability.

Use ArrayKit's `RunwireLazyBinding` and Omnibus's scoped `RunwireBinding` as implementation references, verified against the exact APIs. Reuse their ownership principles, not their entire architecture.

### Integration acceptance

Extend existing persistent-runtime and feature tests with direct framework -> Pathwise and framework -> intermediary -> Pathwise fixtures using the same context objects. Cover normal PHP, Runwire absent, unsupported capability, runtime-only, request cancellation/deadline, completed requests, runtime mismatch, closed scope, nested bindings and explicitly forwarded parallel Fibers. Show no cross-request leakage and no calls that start/stop/complete/close host resources.

Exercise cancellation before work, during bounded loops/waits, immediately before publication, and after successful commit; check cleanup, source ownership, locks and queue lease state. Document cancellation granularity for operations that remain synchronous. Require a representative Foundation 3 host composition check before claiming framework integration readiness.

## Execution sequence and release gates

1. **Remediation batch A:** P01-P06 and P11, with focused regressions in existing suites. Complete security/data-integrity corrections before integration. Preserve existing authority/identity checks and guarded archive extraction.
2. **Remediation batch B:** P07-P10, current static/clone findings, dependency-chain triage and documentation. Confirm Windows behavior on NTFS. Review compatibility before tightening legacy record handling.
3. **Remediation checkpoint:** Run the PHPForge-required processing and complete verification workflow after remediation; inspect automatic edits. Resolve valid analyzer errors and security regressions before adding integration. Record a corrected internal revision for performance comparisons; this is not a separately versioned release.
4. **Runwire integration batch:** Add the borrowed-context boundary and only the operation hooks justified above. Verify absent-Runwire production installs, passed chains, lifecycle isolation and compatibility with consumer subclasses. Update runnable host/intermediary examples and capability documentation together.
5. **Single 4.2.0 candidate:** Require passing exact-final-candidate hosted PHP 8.4/8.5, Windows, prefer-lowest/stable, clean install, optional adapters, benchmarks, stress and security reports, plus Runwire 2.1.1 composition/performance evidence and the Foundation host fixture. Resolve advisories and record any remaining upstream abandonment explicitly. No release readiness claim while a security regression, valid analyzer error, required platform check or integration gate remains open. Release publication/tagging remains a separate action.

### Performance and operational acceptance

Before implementation, retain a matched baseline for affected component operations and representative host requests. Compare current 4.1 to the corrected internal remediation revision, then compare that revision to unbound and bound 4.2.0 separately. Also report the complete 4.1-to-4.2.0 result. These are measurement checkpoints within one release, not additional version targets. Never treat faster unsafe behavior as an acceptable alternative to a required correction; explain the cost of restored guarantees and optimize within them.

Use fixed hardware/runtime, payload mix, native utility versions, adapters, storage/cache state, worker count and concurrency. Include uploads, download streams, archive work, checksum traversal and contention waits where changed. Run repeated steady-state trials at concurrency 1, a moderate level and measured saturation; report median successful RPM/RPS, response correctness, p50/p95/p99, errors/timeouts, CPU, queue growth and continuously sampled process-tree RSS. Start measurements only after host readiness.

Define workload-specific latency, memory, deadline and error budgets from the production-equivalent baseline before accepting results. For stable critical cases use the principles' default **2% maximum median RPM regression**, unless measured variance justifies a documented different tolerance; do not gate on noise or extrapolate host RPM from the current one-sample PHPBench command. Material unexplained regressions block acceptance.

Use bounded persistent-worker smoke/soak runs to check request/scope reset, file descriptors, temporary files, locks and live memory growth. Extend existing fixtures and benchmark subjects rather than creating a parallel benchmark framework or indiscriminate long-running test collection. Any claimed cooperative improvement must show host-level progress under contention alongside equivalent correctness.

## Optional improvements deferred from remediation

Consider additive framed serialization if real consumers need multiline records. Consider explicit early-exit XML reader cleanup and bounded malformed-input behavior after confirming the documented parser contract. Consider native strategy tuning only after repeated matched measurements; the single audit copy sample favored PHP, but establishes no general strategy change.

Do not perform broad reorganizations, replace all traits, introduce a backend manager, parallelize filesystem work automatically, or create pools/workers merely because the optional runtime exists. There is no evidence justifying a 5.0 rewrite or mandatory Runwire dependency.

## Implementation progress tracker

Last updated: 2026-10-09 (Asia/Dhaka).
Pull request: [#24 (draft)](https://github.com/infocyph/Pathwise/pull/24).
Branch: `feature/runwire-2.1.1`. Review baseline: `eead7cc6a1ac498a602f7f0ac0b3ff316ea979d9` (tag 4.1).

**State:** Draft PR opened; remediation implementation and candidate acceptance remain open. The 4.1 successful hosted run is historical baseline evidence only, not approval of this branch.

| Batch / gate | Findings and scope | Implementation | QA / CI | Commit evidence |
| --- | --- | --- | --- | --- |
| Prepare | Draft PR, tracker, preserve tagged 4.1 audit reference | Done | Plan/PR metadata checked | `faf02f7` · [PR #24](https://github.com/infocyph/Pathwise/pull/24) |
| A1 | P01 local containment, P02 bounded upload/materialization + cumulative chunks | **Implementation and functional QA verified**; full release QA remains open | [`a6a153b` workflow](https://github.com/infocyph/Pathwise/actions/runs/37905549989): all four PHPForge QA matrices, Windows 8.4/8.5, adapters, clean install and both benchmarks passed. PHPStan analysis still reports the same 12 preexisting complexity errors (P12), release stress remains running, security-report job skipped after analysis failure. | P01: `947f34a`, `354135b`, `81e5fc9`; P02: `e10a590`–`a6a153b` |
| A2 | P03 serialized graph work, P04 native ZIP, P05 lock correctness | Not started | Pending | — |
| A3 | P06 ClamAV, P11 deduplication | Not started | Pending | — |
| B | P07–P10 Windows/queue/framing/native args, P12 analyzers/clones/deps/docs | Not started | Pending | — |
| Remediation gate | Full PHPForge, security fixtures, Windows CI and matched baseline | Not started | Pending | — |
| Runwire integration | Exact 2.1.1 borrowed context; nested/Fiber safety, cancellation, Foundation 3 host chain | Not started | Pending | — |
| Final 4.2.0 candidate | Hosted matrix, security, adapters, performance, soak/release checklist | Not started | Pending | — |

### Batch A1 verification record

- **P01:** Distinguish local capability from local-path containment; propagate containment denials rather than falling back to the adapter; check local upload root before creating directory. Regression coverage includes default/named local disks and nested symlink escapes.
- **P02:** Bound framework-controlled stream/path staging to the actual configured byte ceiling with one-byte over-read, retain borrowed streams and owned-source failure semantics, fail closed when strict total limits are disabled, and reject aggregate/replacement excess under the chunk-session lock before merge. Mover callback limits remain the host's responsibility; Pathwise checks resulting staged size before hashing.
- **Hosted QA:** Initial candidate `c8f41884` failed five expected `FileSizeExceededException` tests because materialization wrapped the exception; `4178816` corrected this. Subsequent `0e376dc` passed Pest but failed Pint ordering/blank-line rules; `a6a153b` corrected formatting. On [`a6a153b`](https://github.com/infocyph/Pathwise/actions/runs/37905549989), PHP 8.4/8.5 prefer-stable and prefer-lowest QA, Windows 8.4/8.5, optional adapters, clean install and both benchmarks passed. PHPStan reported only the twelve previously documented findings; full workflow remains non-green and release stress is still running.
- **Open release blockers:** Original twelve PHPStan cognitive-complexity findings (P12); uncompleted release stress, matched host RPM and final-candidate verification. Batch A1's focused correctness QA is verified, but **the PR is not release-ready** and the complete remediation gate remains open.

### Tracker policy

- Implement scoped fixes, add faithful regression tests, and run focused QA **before** claiming a batch complete.
- Resolve valid PHPForge, static-analysis, regression and hosted CI failures instead of bypassing gates.
- Record commit SHA, test command/outcome, workflow URL and remaining concerns for each completed batch.
- Run full verification between remediation and Runwire work; preserve public API, performance and native security parity.
- This PR remains draft and unmerged; no final release tag is created during implementation.

## Completion record

The single final version target is **4.2.0**. This audit added a plan only. The reproduced findings remain unfixed; no candidate release, commit, tag or deployment is created by this work. The existing 4.1 hosted run is green, while the current local aggregate gate and the new security/semantic and Runwire integration acceptance gates are open. Update this record with final candidate SHAs, regression results, supported-platform artifacts, dependency outcomes and matched performance evidence as implementation completes.
