Pathwise 4 API and Error Reference
==================================

This page is the compact map of the public 4.0 surface. Feature guides remain
the source for workflow semantics and examples.

Facade and Core Types
---------------------

``Infocyph\Pathwise\PathwiseFacade``
   Stateless convenience entry point for file/directory/compression/read/write,
   upload/download, policy, queue, audit, retention, watcher and indexing
   helpers. It does **not** own persistent storage topology.

``Infocyph\Pathwise\Core\ExecutionStrategy``
   Native/PHP execution strategy enum used by operations that can select an
   implementation path.

``Infocyph\Pathwise\Core\SyncComparison``
   Directory synchronization comparison enum.

Storage
-------

``Storage\StorageContext``
   Instance-scoped named filesystem registry/resolver. Key methods:
   ``filesystem()``, ``configuration()``, ``filesystemNames()``,
   ``defaultFilesystem()``, ``resolve()``, ``path()``, ``localPath()``,
   ``isLocal()``, ``hasFilesystem()``, ``hasDriver()``.

``Storage\StorageFactory``
   Stateless Flysystem constructor/driver metadata. Key methods:
   ``createFilesystem()``, ``officialDrivers()``, ``isOfficialDriver()``,
   ``suggestedPackage()``.

File Management
---------------

``FileManager\FileOperations``
   General file lifecycle, checksums, policy/audit hooks, copy/move/delete and
   transactional operations.

``FileManager\SafeFileReader``
   Bounded/locked safe reader workflows.

``FileManager\SafeFileWriter``
   Safe writing/append/verification and write-oriented transaction behavior.

``FileManager\FileCompression``
   ZIP compression/decompression with archive validation, filters, progress and
   bounded native/PHP execution paths.

``FileManager\FileTransactionJournal``
   Direct-local transaction journal/rollback support used by transactional file
   mutation. Rollback failure is explicit.

``FileManager\SafeSymlinkManager``
   Direct-local safe symbolic-link create/remove/status operations with allowed
   link and target roots.

See :doc:`file-manager` and :doc:`symlink-management`.

Directories
-----------

``DirectoryManager\DirectoryOperations``
   Directory creation/listing/copy/move/delete, synchronization and ZIP
   workflows. Synchronization returns ``Results\SyncReport``.

See :doc:`directory-manager`.

Uploads and Malware Scanning
----------------------------

``StreamHandler\UploadProcessor``
   Upload validation/publication and resumable chunk workflow. Important
   methods include ``setStorageContext()``, ``setDirectorySettings()``,
   ``processUpload()``, ``ingestFile()``, ``ingestSource()``,
   ``processChunkUpload()``, ``processChunkUploadSource()``,
   ``finalizeChunkUpload()``, validation/scanner/extension/chunk setters, and
   ``getInfo()``.

``StreamHandler\UploadSource``
   Framework-neutral source factory: ``fromMover()``, ``fromPath()``,
   ``fromStream()``.

``StreamHandler\UploadMaterialization``
   Owned staging representation used to carry materialized upload metadata and
   deterministic cleanup.

``StreamHandler\MalwareScannerInterface``
   Scanner contract receiving ``MalwareScanRequest`` and returning
   ``MalwareScanVerdict``.

``StreamHandler\MalwareScannerProviderInterface``
   Optional provider identity contract surfaced through uploader status/info.

``StreamHandler\MalwareScanRequest``
   Immutable local scan request metadata.

``StreamHandler\MalwareScanMode``
   ``OFF``, ``WHEN_CONFIGURED``, ``REQUIRED``.

``StreamHandler\MalwareScanStatus``
   Runtime configuration/readiness status exposed by uploader info.

``StreamHandler\MalwareScanVerdict``
   Scanner verdict enum; only ``CLEAN`` is accepted for publication.

``StreamHandler\Scanner\ClamAvDaemonScanner``
   Bounded ClamAV daemon scanner implementation.

See :doc:`upload-processing` and :doc:`malware-scanning`.

Downloads
---------

``StreamHandler\DownloadProcessor``
   Secure metadata/range preparation and streaming. Important methods:
   ``setStorageContext()``, ``setAllowedRoots()``, policy setters,
   ``prepareDownload()``, ``streamChunks()``, ``streamDownload()``.

See :doc:`download-processing`.

Queue
-----

``Queue\FileJobQueue``
   Direct-local durable file queue. Entry points: ``enqueue()``, ``reserve()``,
   ``renew()``, ``acknowledge()``, ``release()``, ``fail()``, ``process()``,
   ``stats()``.

``Queue\QueueReservation``
   Typed opaque lease returned by ``reserve()`` and renewal.

``Queue\FileQueueStateStore``
   Direct-local locked/versioned/crash-safe queue state store used by the queue.

See :doc:`queue`.

Observability
-------------

``Observability\AuditSink``
   Sink interface for structured audit events.

``Observability\AuditTrail``
   Audit event entry point; accepts a local JSONL path or an ``AuditSink``.

``Observability\LocalJsonlAuditSink``
   Direct-local locked JSONL sink with bounded event behavior.

``Observability\CallbackAuditSink``
   Adapter sink for application callbacks/observability pipelines.

``Observability\PartitionedAuditSink``
   Partitioning wrapper for bounding individual audit files/segments.

See :doc:`observability`.

Security and Archives
---------------------

``Security\PolicyEngine``
   Deny-by-default path/operation policy with allow/deny rules, optional
   conditions, and last-match-wins evaluation.

``Security\ZipEntryValidator``
   Archive-entry normalization and safety/limit validation.

``Security\ZipArchiveManifestEntry``
   Validated immutable ZIP manifest entry metadata.

``Security\ZipArchiveExtractor``
   Manifest-driven extraction with collision/type/size/ratio/write-time checks.

See :doc:`security`.

Indexing, Retention and Watchers
--------------------------------

``Indexing\ChecksumIndexer``
   Content-hash indexing, duplicate detection, and local hard-link
   deduplication.

``Retention\RetentionManager``
   Bounded retention by count/age/sort policy.

``Utils\FileWatcher``
   Snapshot/diff/polling watch workflows with typed ``SnapshotDiff`` and
   ``WatchResult`` results.

See :doc:`indexing`, :doc:`retention`, and :doc:`utilities`.

Native Execution
----------------

``Native\NativeCommandRunner``
   Non-blocking bounded argv execution with deterministic termination/cleanup.

``Native\NativeExecutionLimits``
   Immutable timeout/output/grace/poll limits.

``Native\NativeExecutionFailure``
   Typed native failure classification.

``Native\NativeOperationsAdapter``
   Capability-aware native filesystem/archive operation adapter.

See :doc:`native-execution`.

Utilities
---------

``Utils\PathHelper``
   Path normalization/join/validation/relative/temp helpers.

``Utils\FlysystemHelper``
   Low-level storage-neutral helper with direct-local/default/mount routing.
   It is not the recommended persistent-runtime topology registry; use
   ``StorageContext`` for that role.

``Utils\FlysystemPathResolver``
   Low-level Flysystem path resolution support.

``Utils\MetadataHelper``
   Metadata/MIME/ownership/permission helpers across explicit capabilities.

``Utils\PermissionsHelper``
   Local permission operations and normalization.

``Utils\ExtensionPolicy``
   Shared extension allow/block validation.

``Utils\ReadablePathLocalizer``
   Localizes readable adapter-backed data when a local-only consumer requires a
   real path, with explicit cleanup ownership.

``Utils\StreamTransferHelper``
   Stream-copy helper for bounded storage transfers.

``Utils\SerializedValueValidator``
   Defensive serialized-value validation without untrusted object
   instantiation.

``Utils\LocalFileIterator``
   Local iteration helper used by filesystem traversal workloads.

``Utils\Ownership\OwnershipResolverInterface``
   Ownership lookup contract.

``Utils\Ownership\OwnershipResolverFactory``
   Platform-capability resolver selection.

``Utils\Ownership\PosixOwnershipResolver``
   POSIX ownership implementation when the capability is available.

``Utils\Ownership\WindowsOwnershipResolver``
   Windows ownership implementation.

``Utils\Ownership\FallbackOwnershipResolver``
   Capability-safe fallback metadata resolver.

Typed Result Objects
--------------------

Pathwise 4 exposes these immutable/typed workflow results:

``Results\ChunkUploadState``
   ``uploadId``, ``receivedChunks``, ``totalChunks``, ``complete``.

``Results\DeduplicationResult``
   Hard-link deduplication outcome including linked/skipped entries.

``Results\DownloadPreparation``
   Path/name/MIME/size/mtime/ETag/status/range/headers.

``Results\RangeDownloadMetadata``
   Range start/end/content length/partial state.

``Results\DownloadStreamResult``
   Preparation plus ``bytesSent``.

``Results\NativeExecutionResult``
   Native command completion/output/result metadata.

``Results\QueueProcessResult``
   Processed/failed counts for ``FileJobQueue::process()``.

``Results\RetentionResult``
   Retention kept/deleted outcome.

``Results\SnapshotDiff``
   Created/modified/deleted snapshot changes.

``Results\SymlinkStatus``
   Link existence/validity/target status.

``Results\SyncReport``
   Directory synchronization created/updated/deleted outcome.

``Results\WatchResult``
   File-watcher execution/change outcome.

Exception Hierarchy
-------------------

Pathwise-specific failures derive from ``Exceptions\PathwiseException`` where
appropriate. Public exception types are:

* ``AuditException`` — audit sink/event persistence failure;
* ``CompressionException`` — compression/archive workflow failure;
* ``DirectoryOperationException`` — directory operation/sync failure;
* ``DownloadException`` — download policy/range/stream failure;
* ``FileAccessException`` — file access/read/write failure;
* ``FileNotFoundException`` — required path missing;
* ``FileSizeExceededException`` — configured size limit exceeded;
* ``InvalidPathException`` — invalid/unsafe path;
* ``MalwareScannerException`` — scanner implementation/protocol failure;
* ``MissingExtensionException`` — required PHP extension missing;
* ``NativeExecutionException`` — bounded native execution failure;
* ``PolicyViolationException`` — policy rejected an operation;
* ``QueueException`` — queue state/lease/durability failure;
* ``StorageCapabilityException`` — storage lacks a required capability;
* ``TransactionRollbackException`` — rollback itself failed;
* ``TransactionStateException`` — invalid transaction lifecycle/state;
* ``UnsafeArchiveEntryException`` — unsafe ZIP manifest/entry;
* ``UnsupportedStorageOperationException`` — operation cannot be represented by
  the selected storage;
* ``UploadException`` — upload validation/materialization/scanner/publication
  failure.

Standard ``InvalidArgumentException``/``UnexpectedValueException`` are also used
for programmer/configuration errors where a Pathwise operational exception
would be misleading.

Failure handling should depend on exception type and stable semantics, not on
backend-specific message strings.
