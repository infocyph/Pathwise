Upload Processing
=================

Namespace: ``Infocyph\Pathwise\StreamHandler``

``UploadProcessor`` is the Pathwise 4 upload pipeline for validated HTTP
uploads, trusted application ingestion, framework-neutral upload sources, and
resumable chunks. It is mutable configuration state; create/configure it for the
lifecycle of one application policy rather than sharing it across unrelated
policies.

Core Entry Points
-----------------

* ``processUpload(array $file)`` — PHP HTTP upload; preserves
  ``is_uploaded_file()`` provenance.
* ``ingestFile(array $file)`` — trusted application/CLI input using ``$_FILES``
  shaped metadata.
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
validation. Pathwise secures the staged file, measures its actual size, and
removes staging state in a ``finally`` path. Cleanup failure never replaces the
primary validation/storage/scanner failure.

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

* ``setValidationProfile('image'|'video'|'document')``;
* ``setValidationSettings(array $allowedFileTypes, int $maxFileSize)``;
* ``setExtensionPolicy(array $allowedExtensions = [], array $blockedExtensions = [])``;
* ``setImageValidationSettings(int $maxImageWidth = 0, int $maxImageHeight = 0)``;
* ``setStrictContentTypeValidation(bool $enabled = true)``;
* ``setNamingStrategy('hash'|'timestamp')``;
* ``setChunkLimits(int $maxChunkCount = 0, int $maxChunkSize = 0)``.

The built-in blocked extension set includes executable/server-side script types.
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
not mutate the scan input, rechecks source size around scanning, removes the
scan copy on every path, and maps scanner failures to stable ``UploadException``
messages while retaining the original exception as ``previous``.

See :doc:`malware-scanning` for daemon limits/provider/status details.

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
validates the assembled payload, publishes it according to the naming policy,
and cleans session artifacts only after successful publication. Direct-local
chunk storage uses a session lock; non-local adapters cannot provide the same OS
locking semantics, so local chunk staging is the recommended production model.

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
secrets or raw untrusted payload contents in audit metadata.

Example Hardened Policy
-----------------------

.. code-block:: php

   $uploader = new UploadProcessor();
   $uploader->setStorageContext($storage);
   $uploader->setDirectorySettings('objects://uploads', tempDir: sys_get_temp_dir());
   $uploader->setValidationProfile('document');
   $uploader->setExtensionPolicy(['pdf', 'doc', 'docx']);
   $uploader->setStrictContentTypeValidation(true);
   $uploader->setChunkLimits(maxChunkCount: 100, maxChunkSize: 4 * 1024 * 1024);
   $uploader->setMalwareScanner($scanner);
   $uploader->setMalwareScanMode(MalwareScanMode::REQUIRED);

See :doc:`storage-context`, :doc:`security`, and
:doc:`performance-portability` for runtime, trust-boundary, and memory guidance.
