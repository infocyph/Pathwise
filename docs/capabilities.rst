Capabilities
============

Pathwise 4 combines storage-neutral filesystem I/O with explicit local-only
capabilities and hardened workflow primitives.

Runtime Model
-------------

* ``PathwiseFacade`` is stateless convenience for common operations.
* ``StorageFactory`` is a stateless Flysystem constructor/driver metadata helper.
* ``StorageContext`` owns persistent named storage topology for one application
  runtime/generation.
* Direct absolute paths remain local. Context relative/scheme paths resolve
  through the injected context.
* Low-level ``FlysystemHelper`` routing exists for standalone utility use, not
  as the recommended multi-application registry.

File and Directory Operations
-----------------------------

``Infocyph\Pathwise\FileManager``
   ``FileOperations``, ``SafeFileReader``, ``SafeFileWriter``,
   ``FileCompression``, ``SafeSymlinkManager`` and local transaction support.

``Infocyph\Pathwise\DirectoryManager``
   ``DirectoryOperations`` for recursive lifecycle, listing/filtering, sync and
   archive workflows with typed ``SyncReport`` output.

Capabilities include checksums, verified writes/copies, locking, atomic local
replacement, safe rollback, ZIP hardening, filters/progress, and explicit
storage/platform capability failures.

Storage
-------

``Infocyph\Pathwise\Storage``
   ``StorageContext`` and ``StorageFactory``.

Flysystem adapter construction covers local, FTP, memory, read-only,
path-prefixing, S3 variants, Azure, Google Cloud Storage, GridFS, SFTP, WebDAV
and ZIP adapter packages when installed. Custom driver factories are scoped to
one ``StorageContext``.

Uploads
-------

``UploadProcessor`` provides:

* genuine PHP HTTP upload provenance checking;
* trusted application/CLI ingestion;
* framework-neutral ``UploadSource`` mover/path/stream ingestion;
* private owned staging and deterministic cleanup;
* validation profiles, MIME/size/extension/signature/image checks;
* hash/timestamp naming with deterministic collision checks;
* resumable chunk manifests/state/finalization;
* typed malware scanner contracts and explicit scan modes;
* direct ``StorageContext`` integration.

Downloads
---------

``DownloadProcessor`` provides:

* allowed-root/extension/hidden-file/max-size policy;
* safe filenames and response-oriented metadata;
* byte range parsing and typed ``DownloadPreparation``;
* range-aware iterable ``streamChunks()`` with source cleanup;
* direct output-stream copying through ``streamDownload()``;
* stale-preparation revalidation;
* direct ``StorageContext`` integration.

Security
--------

``PolicyEngine``
   Deny-by-default path/operation policy with conditions and last-match-wins
   rules.

Archive security
   Manifest-driven ZIP validation/extraction with path, collision, entry-type,
   size, compression-ratio, source-symlink and write-time checks.

Symbolic links
   ``SafeSymlinkManager`` validates allowed link/target roots and existing
   targets for direct-local link lifecycle.

Serialization
   Defensive serialized-value validation without object instantiation.

Native execution
   Bounded argv execution with timeout/output limits and deterministic
   termination/cleanup.

Operations and Data Management
------------------------------

``Queue\FileJobQueue``
   Direct-local durable queue with typed opaque leases, renewal, stale-worker
   rejection, strict versioned state and crash-safe persistence.

``Observability``
   ``AuditTrail`` plus local JSONL, callback and partitioned sinks.

``Indexing\ChecksumIndexer``
   Content index, duplicate detection and local hard-link deduplication.

``Retention\RetentionManager``
   Count/age-based retention with typed result.

``Utils\FileWatcher``
   Snapshot, diff and bounded polling watcher behavior.

Typed Results
-------------

Important public results include ``ChunkUploadState``, ``DownloadPreparation``,
``RangeDownloadMetadata``, ``DownloadStreamResult``, ``QueueProcessResult``,
``SyncReport``, ``SymlinkStatus``, ``SnapshotDiff``, ``WatchResult``,
``RetentionResult``, ``DeduplicationResult`` and ``NativeExecutionResult``.

Local-Only Capabilities
-----------------------

Pathwise does not pretend that object storage can provide OS primitives. These
remain direct-local/platform dependent:

* ``flock`` queue/session coordination;
* native subprocess paths;
* POSIX/Windows ownership operations;
* symlink/hard-link semantics;
* local transaction/atomic rename guarantees.

See :doc:`performance-portability` for the complete capability table.

Requirements
------------

Required:

* PHP 8.4+
* ``ext-fileinfo``
* ``league/flysystem`` 3.x
* ``psr/log`` 3.x

Optional extensions/adapters are installed only for selected capabilities.

Read Next
---------

* :doc:`quickstart`
* :doc:`storage-context`
* :doc:`storage-adapters`
* :doc:`security`
* :doc:`api-reference`
