Performance, Memory and Portability
===================================

Pathwise prefers streaming, bounded state, and explicit capabilities over hidden
buffering or platform emulation. The fastest safe implementation depends on the
storage and operating-system capabilities available to a workload. Security
checks are benchmarked, not bypassed.

Streaming Uploads
-----------------

``UploadSource`` stages framework-neutral input into a private local file before
upload validation. This is intentional: MIME/signature/image/scanner checks need
a stable readable payload, and scanner integrations receive a real local path.

Guidelines:

* pass streams/movers rather than reading an entire request into a PHP string;
* keep ``tempDir`` on fast local storage;
* configure realistic max file/chunk sizes;
* use local chunk staging even when the final destination is object storage;
* understand that malware scanning may require one additional bounded local copy
  of the payload, but not a full PHP string copy.

Strict uploads still materialize once for the validation/publication pipeline.
Same-filesystem local publication prefers rename. Cross-device local publication
uses a destination-side temporary file so a partially copied artifact is not
published under its final name. Remote storage uses the strongest temporary
object/move semantics exposed by the adapter without pretending those semantics
are POSIX atomic rename.

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

``PublicFileResolver`` resolves a trusted public root plus relative candidate
before response transport. That canonical resolution/metadata cost is deliberate
and should happen once at the filesystem trust boundary; Webrick/Runwire should
consume the resolved artifact rather than reparsing the raw URL as a disk path.

StorageContext Cost Model
-------------------------

``StorageContext`` normalizes configuration once and lazily creates each
``FilesystemOperator`` on first use. Subsequent resolutions reuse the same
operator inside that context. Two contexts intentionally do not share operator
instances or custom driver factories merely because names match.

For hot request/worker paths, construct the context once per application runtime
or configuration generation and inject it into processors/services. Do not
rebuild remote SDK clients for every file operation.

Mutable ``UploadProcessor`` instances are different: their scanner, roots,
validation rules and trust profile are request/policy state. Create them as
transient/request-scoped services, or from immutable application policy; do not
mutate one shared processor concurrently across unrelated requests.

Persistent Runtime State
------------------------

Persistent workers make process-lifetime memory decisions visible. Pathwise 4.1
therefore avoids retaining arbitrary request-derived normalized paths or command
names in static caches.

``PathHelper::normalize()`` is intentionally uncached for attacker-like
high-cardinality input. Normalizing a string is normally far cheaper than the
filesystem I/O that follows it, and the release benchmark measures that cost
explicitly. ``NativeCommandRunner`` likewise performs capability lookup without
retaining arbitrary caller-derived command names across requests.

``FlysystemHelper`` global mounts/default filesystem are compatibility bootstrap
configuration. Prefer ``StorageContext`` for isolated application/worker
composition, and call ``FlysystemHelper::reset()`` when a test or runtime
intentionally replaces global compatibility configuration.

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
     - Trusted direct-local only, when capability exists
     - Requires explicit localization first
   * - Hard-link deduplication
     - Filesystem-dependent
     - Not supported as object-store semantics

A workflow that needs a local-only capability should use a local working area
and move the resulting artifact to/from remote storage explicitly.

Native Filesystem Acceleration
------------------------------

Native execution remains an optimization for trusted direct-local filesystem
work, not an authorization boundary. ``ExecutionStrategy::PHP`` forces portable
PHP, ``AUTO`` chooses an eligible native filesystem implementation or preserves
the PHP semantics, and ``NATIVE`` requires the native capability.

The legacy ``NativeCommandRunner`` remains bounded and shell-free for Pathwise
internal/source-compatible use, but generic application process use is
deprecated. Runwire owns application/Foundation process execution.

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
entry validation, duplicate/collision checks, type checks, entry-name/path/depth
bounds, size/ratio limits, and write-time revalidation. These checks are release
requirements and must not be disabled to improve benchmark numbers.

Large archives should be constrained by entry count, individual expanded size,
total expanded size, compression ratio, normalized path/name length and nesting
depth according to application policy. Raw native ``unzip`` is not a benchmark
substitute for the strict extractor.

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
* executable availability for trusted native filesystem commands;
* adapter-specific metadata/checksum/MIME support;
* filesystem semantics around atomic replacement and durability.

Pathwise surfaces unsupported capabilities explicitly instead of claiming
identical semantics everywhere.

Release Workloads
-----------------

The release benchmark ``Pathwise4ReleaseBench`` includes security-preserving
subjects for:

* high-cardinality canonical containment and uncached normalization;
* framework-neutral source materialization;
* strict upload validation + controlled publication;
* strict upload malware staging with a local scanner contract;
* hardened ZIP validation/extraction;
* trusted ``PHP`` versus ``AUTO`` file-copy paths;
* trusted public-file resolution;
* range download streaming;
* ``StorageContext`` hot resolution;
* atomic/staged writers, checksum indexing, hard-link deduplication and queue
  reserve/release/renew/acknowledge behavior.

Additional benchmark/stress workloads under ``benchmarks/`` cover workflow,
stream, file/directory, data-management and higher-volume queue/chunk/transaction
behavior. Run the release benchmark through the Composer script:

.. code-block:: bash

   composer benchmark:release

Run explicit stress with:

.. code-block:: bash

   composer stress:release

Release acceptance is based primarily on correctness, bounded memory/state, and
no regressions that indicate accidental full-file buffering or security-check
removal. Wall-clock values are recorded as baselines rather than brittle
universal thresholds because CI hardware and storage vary.

See :doc:`trust-boundaries` for persistent-worker and cross-library ownership
guidance.
