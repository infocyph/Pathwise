Upload Processing
=================

Namespace: ``Infocyph\Pathwise\StreamHandler``

``UploadProcessor`` is the Pathwise 4 upload pipeline for validated HTTP
uploads, trusted application ingestion, framework-neutral upload sources, and
resumable chunks. It is mutable configuration state; create/configure it for one
application policy/request lifetime rather than sharing it across unrelated
concurrent requests.

Core Entry Points
-----------------

* ``processUpload(array $file)`` — PHP HTTP upload; preserves
  ``is_uploaded_file()`` provenance until Pathwise-owned staging.
* ``ingestFile(array $file)`` — trusted application/CLI provenance using
  ``$_FILES``-shaped metadata; content still passes configured validation.
* ``ingestSource(UploadSource $source)`` — framework-neutral typed source.
* ``processChunkUpload(...)`` — one ``$_FILES``-shaped chunk.
* ``processChunkUploadSource(...)`` — one typed source chunk.
* ``finalizeChunkUpload($uploadId)`` — explicitly validate and publish a
  complete resumable upload.

Typed UploadSource Ownership
----------------------------

``UploadSource`` lets frameworks cross into Pathwise without synthesizing an
HTTP upload array themselves:

* ``UploadSource::fromMover()`` receives a Pathwise-owned target path. Use it for
  uploaded-file abstractions with ``moveTo()``-style APIs.
* ``UploadSource::fromPath()`` copies a borrowed path. With ``owned: true``, the
  source is consumed after successful staging.
* ``UploadSource::fromStream()`` reads from the caller-owned stream's current
  position and never closes that caller stream.

Every source is materialized into a private local staging directory/file before
validation. Pathwise secures the staged file, measures its actual size, tracks
its identity across validation/scanning, and removes staging state in a
``finally`` path. Cleanup failure never replaces the primary
validation/storage/scanner failure.

.. code-block:: php

   use Infocyph\Pathwise\StreamHandler\UploadSource;

   $source = UploadSource::fromMover(
       mover: fn (string $target): void => $uploadedFile->moveTo($target),
       clientFilename: $uploadedFile->getClientFilename() ?? 'upload.bin',
       size: $uploadedFile->getSize(),
       clientMediaType: $uploadedFile->getClientMediaType(),
       error: $uploadedFile->getError(),
   );

   $finalPath = $uploader->ingestSource($source);

A non-success upload error code is rejected before a mover is invoked.

Strict Untrusted-Data Profile
-----------------------------

Use ``UploadTrustProfile::UNTRUSTED_DATA`` for internet-facing uploads and other
caller-controlled content. It is a small opt-in policy surface, not an execution
or sandbox DSL.

.. code-block:: php

   use Infocyph\Pathwise\StreamHandler\UploadTrustProfile;

   $uploader->setTrustProfile(UploadTrustProfile::UNTRUSTED_DATA);

The strict profile guarantees:

* finite default chunk count and per-chunk size limits;
* server-generated/hash final naming;
* strict MIME/extension/signature checks where supported;
* private staging before validation and publication;
* restrictive local staging and published-file permissions;
* controlled publication with canonical containment rechecks;
* no preservation of caller executable permission bits.

Scanner policy remains explicit. Strict mode does not silently enable or invent
a scanner: use ``MalwareScanMode::REQUIRED`` when malware scanning is mandatory.
The original client filename is retained as source metadata only; it never
becomes the authoritative publication path.

StorageContext Integration
--------------------------

Persistent runtimes should inject ``StorageContext`` directly. Do this before
calling path-dependent configuration such as ``setDirectorySettings()``.

.. code-block:: php

   use Infocyph\Pathwise\Storage\StorageContext;
   use Infocyph\Pathwise\StreamHandler\UploadProcessor;

   $storage = new StorageContext([
       'objects' => ['filesystem' => $objectFilesystem],
   ], 'objects');

   $uploader = new UploadProcessor();
   $uploader->setStorageContext($storage);
   $uploader->setDirectorySettings(
       uploadDir: 'objects://uploads',
       useDateDirectories: true,
       tempDir: sys_get_temp_dir(),
   );

Relative and ``name://`` processor paths route through that context. Direct
absolute filesystem paths remain local. No global mount is required or created.
Use a direct-local temporary path for efficient source staging, chunk locking,
and workflows that depend on OS-level atomicity.

Validation Policy
-----------------

Important controls include:

* ``setTrustProfile(UploadTrustProfile $profile)``;
* ``setValidationProfile('image'|'video'|'document')``;
* ``setValidationSettings(array $allowedFileTypes, int $maxFileSize)``;
* ``setExtensionPolicy(array $allowedExtensions = [], array $blockedExtensions = [])``;
* ``setImageValidationSettings(int $maxImageWidth = 0, int $maxImageHeight = 0)``;
* ``setStrictContentTypeValidation(bool $enabled = true)``;
* ``setNamingStrategy('hash'|'timestamp')``;
* ``setChunkLimits(int $maxChunkCount = 0, int $maxChunkSize = 0)``.

The built-in blocked extension set is defense in depth, not the primary trust
boundary. A text file can contain executable source and a PHP file can remain
inert data when an application stores it without interpreting it. Pathwise does
not inspect source strings such as ``exec(`` or shell syntax and call them
unsafe merely because those bytes exist.

Strict content validation checks extension/MIME agreement plus lightweight magic
signatures for supported formats. Image profiles can also enforce dimensions.
The authoritative size is measured from the staged/current payload rather than
trusting caller metadata.

Malware Scanner Contract
------------------------

Pathwise 4 uses ``MalwareScannerInterface`` and an explicit ``MalwareScanMode``:

* ``OFF`` — do not scan;
* ``WHEN_CONFIGURED`` — default; scan only when a scanner exists;
* ``REQUIRED`` — fail closed unless a scanner is configured and returns
  ``MalwareScanVerdict::CLEAN``.

.. code-block:: php

   use Infocyph\Pathwise\StreamHandler\MalwareScanMode;
   use Infocyph\Pathwise\StreamHandler\Scanner\ClamAvDaemonScanner;

   $uploader->setMalwareScanner(new ClamAvDaemonScanner(
       endpoint: 'unix:///run/clamav/clamd.ctl',
   ));
   $uploader->setMalwareScanMode(MalwareScanMode::REQUIRED);

Pathwise creates a private local scan copy and scans before MIME/signature/image
parsing. It accepts only an explicit clean verdict, checks that the scanner did
not mutate its private scan input, revalidates the staged source identity after
scanning, removes scan state on every path, and maps scanner failures to stable
``UploadException`` messages while retaining the original exception as
``previous``.

A process-backed scanner belongs in an application/Foundation adapter that
implements ``MalwareScannerInterface`` and uses Runwire for the trusted process.
Pathwise itself does not accept an executable path for hostile-upload scanning.
See :doc:`malware-scanning` and :doc:`trust-boundaries`.

Controlled Publication
----------------------

For direct-local storage Pathwise publishes with atomic rename when the staged
source and target filesystem permit it. When a local cross-device rename is not
possible, Pathwise copies to an exclusive destination-side temporary file,
flushes/verifies it, rechecks canonical containment, then atomically renames the
temporary file into place. Partial temporary artifacts are cleaned on failure.

For Flysystem/object storage, Pathwise uses temporary-object/move behavior the
adapter can provide. It verifies size and checksum when the adapter exposes that
capability, but it does not claim POSIX atomic rename, inode identity, symlink or
Unix-mode guarantees for remote storage.

Resumable Uploads
-----------------

.. code-block:: php

   $state = $uploader->processChunkUploadSource(
       source: $chunkSource,
       uploadId: 'session_42',
       chunkIndex: 0,
       totalChunks: 4,
       originalFilename: 'video.mp4',
   );

   if ($state->complete) {
       $finalPath = $uploader->finalizeChunkUpload('session_42');
   }

Upload IDs allow only letters, numbers, ``-`` and ``_`` and are length bounded.
Chunk metadata is persisted in a manifest and must remain consistent across the
session. ``ChunkUploadState`` exposes ``uploadId``, ``receivedChunks``,
``totalChunks`` and ``complete``.

Finalization is explicit. Pathwise assembles all chunks into a staging object,
validates/scans the assembled payload through the same final pipeline, publishes
it according to the naming policy, and cleans session artifacts only after
successful publication. Direct-local chunk storage uses a session lock;
non-local adapters cannot provide the same OS locking semantics, so local chunk
staging is the recommended production model.

Hash Naming and Collision Safety
--------------------------------

Hash naming is based on the actual payload content. If the deterministic target
already exists, Pathwise compares source/destination checksums and reuses it
only when the content matches. Different content at the same deterministic name
is rejected rather than overwritten.

Logging
-------

``setLogger(LoggerInterface $logger)`` accepts any PSR-3 logger. Upload success
and failure logs can include caller-supplied scalar metadata. Avoid placing
secrets, absolute sensitive paths, or raw untrusted payload contents in audit
metadata.

Example Hardened Policy
-----------------------

.. code-block:: php

   use Infocyph\Pathwise\StreamHandler\UploadTrustProfile;

   $uploader = new UploadProcessor();
   $uploader->setStorageContext($storage);
   $uploader->setTrustProfile(UploadTrustProfile::UNTRUSTED_DATA);
   $uploader->setDirectorySettings('objects://uploads', tempDir: sys_get_temp_dir());
   $uploader->setValidationProfile('document');
   $uploader->setExtensionPolicy(['pdf', 'doc', 'docx']);
   $uploader->setChunkLimits(maxChunkCount: 100, maxChunkSize: 4 * 1024 * 1024);
   $uploader->setMalwareScanner($scanner);
   $uploader->setMalwareScanMode(MalwareScanMode::REQUIRED);

Uploaded content remains data. A later privileged operation must independently
validate intent, authorize the artifact/operation, resolve the artifact through
Pathwise, and use the application's trusted execution layer such as Runwire.

See :doc:`storage-context`, :doc:`security`, :doc:`trust-boundaries`, and
:doc:`performance-portability` for runtime, trust-boundary, and memory guidance.
