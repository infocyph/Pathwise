Performance, Memory and Portability
===================================

Pathwise prefers streaming, bounded state, and explicit capabilities over hidden
buffering or platform emulation. The fastest safe implementation depends on the
storage and operating-system capabilities available to a workload.

Streaming Uploads
-----------------

``UploadSource`` always stages framework-neutral input into a private local file
before upload validation. This is intentional: MIME/signature/image/scanner
checks need a stable readable payload, and scanner integrations receive a real
local path.

Guidelines:

* pass streams/movers rather than reading an entire request into a PHP string;
* keep ``tempDir`` on fast local storage;
* configure realistic max file/chunk sizes;
* use local chunk staging even when the final destination is object storage;
* understand that malware scanning may require one additional bounded local copy
  of the payload, but not a full PHP string copy.

The final destination transfer uses streams where the source/destination storage
requires cross-filesystem copying.

Streaming Downloads
-------------------

``DownloadProcessor::streamChunks()`` is the framework-neutral large-file body
path. It:

* opens the source lazily;
* seeks directly when possible;
* otherwise discards bytes incrementally to a range offset;
* reads at most the configured chunk size;
* stops exactly at the prepared range length;
* closes the source on completion, exception, or early generator disposal.

Do not replace this with ``read()``/full-string buffering in an HTTP integration.
The default chunk size is 64 KiB and can be changed with ``setChunkSize()``.

StorageContext Cost Model
-------------------------

``StorageContext`` normalizes configuration once and lazily creates each
``FilesystemOperator`` on first use. Subsequent resolutions reuse the same
operator inside that context. Two contexts intentionally do not share operator
instances or custom driver factories merely because names match.

For hot request/worker paths, construct the context once per application runtime
or configuration generation and inject it into processors/services. Do not
rebuild remote SDK clients for every file operation.

Local vs Remote Capabilities
----------------------------

Flysystem makes storage-neutral I/O portable; it cannot make OS primitives
portable. Pathwise therefore keeps these distinctions explicit:

.. list-table::
   :header-rows: 1

   * - Capability
     - Local filesystem
     - Remote/object storage
   * - Read/write/copy streams
     - Yes
     - Adapter-dependent, generally yes
   * - Atomic native rename semantics
     - Available where filesystem supports it
     - Not assumed
   * - ``flock`` queue/session locking
     - Yes
     - Not emulated
   * - POSIX/Windows ownership metadata
     - Platform-dependent
     - Not assumed
   * - Symbolic links
     - Platform/filesystem-dependent
     - Not emulated
   * - Native command path
     - Yes when executable/capability exists
     - Requires localization first
   * - Hard-link deduplication
     - Filesystem-dependent
     - Not supported as object-store semantics

A workflow that needs a local-only capability should use a local working area
and move the resulting artifact to/from remote storage explicitly.

Native Execution Limits
-----------------------

Native execution is an optimization/capability path, not an excuse for
unbounded subprocesses. ``NativeExecutionLimits`` defaults to:

* timeout: 300 seconds;
* stdout cap: 4 MiB;
* stderr cap: 4 MiB;
* termination grace: 1 second;
* poll interval: 10,000 microseconds.

Applications may choose tighter limits for request paths. Pathwise uses argv
execution rather than shell command concatenation and terminates/cleans child
processes deterministically when limits fail.

Directory Workloads
-------------------

Directory sync/indexing cost scales with both entry count and the selected
comparison strategy. Size/mtime comparisons avoid content reads; checksum
comparison reads file contents and is therefore more expensive but stronger.

For very large trees:

* choose the weakest comparison that satisfies correctness;
* avoid unnecessary checksum re-scans;
* keep destination-outside-source validation enabled;
* expect network-backed adapters to amplify per-entry metadata costs;
* benchmark against the production adapter, not only local tmpfs/SSD behavior.

Archives
--------

Archive safety checks add deliberate work: manifest construction, normalized
entry validation, duplicate/collision checks, type checks, size/ratio limits,
and write-time revalidation. These checks are release requirements and should
not be disabled to improve benchmark numbers.

Large archives should be constrained by entry count, individual expanded size,
total expanded size, and compression ratio according to application policy.

Queue Scaling
-------------

``FileJobQueue`` is a durable **local file queue**, not a distributed broker.
Every mutation locks and validates bounded versioned state and persists it
crash-safely. This is appropriate for lightweight local coordination and worker
state, not very high-throughput multi-host messaging.

Constructor limits bound jobs, queue bytes, payload bytes, and lease timeout.
If workload scale no longer fits those limits efficiently, move the queueing
responsibility to a purpose-built broker rather than weakening Pathwise's state
validation/durability.

Audit, Retention and Watchers
-----------------------------

* Prefer ``PartitionedAuditSink`` when audit volume could make one JSONL file
  unbounded.
* Retention scans filesystem metadata and should be scheduled at a cadence
  appropriate to directory size.
* ``FileWatcher`` is polling/snapshot based. Its interval/duration directly
  trade CPU/I/O for detection latency; it is not an OS-native event stream.

Cross-Platform Notes
--------------------

The release matrix covers Linux and Windows on PHP 8.4/8.5. Still account for:

* Windows path case/drive/UNC behavior;
* availability/permissions for symbolic and hard links;
* absence of POSIX functions on Windows;
* executable availability for native archive/filesystem commands;
* adapter-specific metadata/checksum/MIME support;
* filesystem semantics around atomic replacement and durability.

Pathwise surfaces unsupported capabilities explicitly instead of claiming
identical semantics everywhere.

Release Workloads
-----------------

The repository carries benchmark/stress workloads under ``benchmarks/``:

* ``ReleaseWorkloadsBench`` — large reader/download chunks, 1,000-entry sync,
  chunk assembly, queue and transaction workloads;
* ``WorkflowContractsBench`` — workflow-level contract costs;
* ``StreamHandlerBench`` — upload/download hot paths;
* ``FileAndDirectoryBench`` / ``DataManagementBench`` / helper benches;
* ``benchmarks/stress/ReleaseStress.php`` — opt-in higher-volume queue, chunk,
  and transaction stress.

Run ordinary benchmarks with the project's PHPBench configuration and explicit
stress with:

.. code-block:: bash

   vendor/bin/phpbench run benchmarks/stress/ReleaseStress.php --bootstrap=vendor/autoload.php

Release acceptance is based primarily on correctness, bounded memory/state, and
no regressions that indicate accidental full-file buffering. Wall-clock values
are recorded as baselines rather than brittle universal pass/fail thresholds,
because CI hardware and storage vary.
