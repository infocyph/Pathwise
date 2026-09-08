Upload Processing
=================

Namespace: ``Infocyph\Pathwise\StreamHandler``

Where it fits:

* Use this module for HTTP uploads that need validation, deterministic naming,
  resumable chunk flow, and layered upload hardening.

``UploadProcessor`` supports:

* HTTP upload handling through ``processUpload()`` (requires PHP's verified
  ``is_uploaded_file()`` provenance).
* Explicit trusted CLI/application ingestion through ``ingestFile()``.
* Framework-neutral typed ingestion through ``UploadSource`` and
  ``ingestSource()``.
* Typed chunk ingestion through ``processChunkUploadSource()``.
* Validation profiles: ``image``, ``video``, ``document``.
* MIME and size validation with optional image dimension validation.
* Extension allowlist/blocklist policy.
* Naming strategies (hash/timestamp).
* Chunked/resumable uploads:
  * ``processChunkUpload()``
  * ``processChunkUploadSource()``
  * ``finalizeChunkUpload()``
* Upload ID safety validation for chunk/session identifiers.
* Strict content checks:
  * extension <> MIME agreement
  * lightweight file signature verification for common formats
* First-class ``MalwareScannerInterface`` support.

Storage notes:

* Uses Flysystem operations for chunk manifests and destination writes.
* Supports mounted/default filesystem routing through helper resolution.
* ``UploadSource`` materialization always uses a Pathwise-owned local staging
  file. A mounted/default filesystem temp setting therefore falls back to the
  local system temp directory for source materialization.
* For adapter setup (S3/SFTP/FTP/custom), see ``storage-adapters``.

Typed Upload Sources
--------------------

Use ``UploadSource`` when a framework, PSR-style uploaded-file object, stream,
or application path should enter the Pathwise upload pipeline without first
being converted to a synthetic ``$_FILES`` array by the application layer.

Supported source forms:

* ``UploadSource::fromMover()`` for framework-owned ``moveTo()`` style APIs.
* ``UploadSource::fromPath()`` for borrowed or explicitly owned paths.
* ``UploadSource::fromStream()`` for caller-owned readable streams.

Pathwise materializes each source into a private local staging file before
validation. The materialized file size is authoritative; optional source size
metadata is advisory and never replaces the actual staged size check. Staging
files are removed in a ``finally`` path after success or failure.

A borrowed path is copied and remains untouched. An owned path is consumed once
its staging copy succeeds, even when later validation rejects the upload. A
caller-owned stream is read from its current position and is never closed by
Pathwise.

Framework mover example:

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

Typed chunk example:

.. code-block:: php

   $state = $uploader->processChunkUploadSource(
       source: $source,
       uploadId: 'session-42',
       chunkIndex: 0,
       totalChunks: 4,
       originalFilename: 'video.mp4',
   );

Non-success upload error codes are rejected before a mover callback is invoked,
so an invalid framework upload is not materialized unnecessarily.

Malware Scanning
----------------

Production malware scanning uses the typed ``MalwareScannerInterface``. The
scanner receives a ``MalwareScanRequest`` containing a Pathwise-owned local
regular file, the actual size, and the normalized extension.

Pathwise never passes a Flysystem URI, mounted path, symlink, or caller-owned
file directly to the scanner. The upload is copied into a private ``0700`` scan
directory and the scan file is forced to ``0600`` permissions. The scan copy is
removed in a ``finally`` path after every verdict or failure.

.. code-block:: php

   use Infocyph\Pathwise\StreamHandler\MalwareScannerInterface;
   use Infocyph\Pathwise\StreamHandler\MalwareScanRequest;
   use Infocyph\Pathwise\StreamHandler\MalwareScanVerdict;

   final class ClamScanner implements MalwareScannerInterface
   {
       public function scan(MalwareScanRequest $request): MalwareScanVerdict
       {
           // Scan $request->localPath with clamd, ICAP, or another engine.
           return MalwareScanVerdict::CLEAN;
       }
   }

   $uploader->setMalwareScanner(new ClamScanner());
   $uploader->setRequireMalwareScan(true);

Only an explicit ``MalwareScanVerdict::CLEAN`` is accepted. ``MALICIOUS``,
``SUSPICIOUS``, and ``UNKNOWN`` all reject the upload. Scanner/backend
exceptions fail closed as ``Malware scanner failed.`` while the original
throwable is retained as the previous exception for internal diagnostics.

The hardened validation order is:

#. upload/error and HTTP provenance checks
#. metadata size rejection
#. authoritative actual-size validation
#. extension allow/block policy
#. materialize private local malware-scan copy
#. malware scan
#. MIME detection and MIME allowlist
#. extension-to-MIME and magic-signature checks
#. image/format-specific parsing
#. final naming and publication

This deliberately keeps attacker-controlled content away from MIME/signature
and image parsers until the configured malware boundary has accepted it.

The scan copy is immutable by contract. Pathwise verifies its size after the
scanner returns and rejects scanner mutation. Pathwise also rechecks the source
size around scanning and fails if the source changes during scan preparation or
execution.

When ``setRequireMalwareScan(true)`` is enabled and no scanner is configured,
Pathwise fails before MIME detection or deeper parsing.

For resumable uploads, individual chunks are not treated as independently safe
files. Pathwise scans the fully assembled staging object during
``finalizeChunkUpload()`` before deeper content validation and publication.
This avoids both per-chunk scanner cost and false assurance when malicious
structure spans chunk boundaries.

Security Hardening Controls
---------------------------

``UploadProcessor`` exposes explicit controls for upload policy:

* ``setExtensionPolicy(array $allowedExtensions = [], array $blockedExtensions = [])``
  to enforce extension allow/deny policies.
* ``setChunkLimits(int $maxChunkCount = 0, int $maxChunkSize = 0)``
  to cap chunk count and per-chunk size.
* ``setRequireMalwareScan(bool $required = true)``
  to reject uploads if scanner execution is required but unavailable.
* ``setStrictContentTypeValidation(bool $enabled = true)``
  to enforce extension-to-MIME agreement and signature checks.

Chunk upload IDs are validated and must contain only:

* letters/numbers
* ``-`` and ``_``

Identifiers with separators such as ``/`` or traversal patterns are rejected.

Examples
--------

Basic single upload:

.. code-block:: php

   use Infocyph\Pathwise\StreamHandler\UploadProcessor;

   $uploader = new UploadProcessor();
   $uploader->setDirectorySettings('/tmp/uploads');
   $uploader->setValidationProfile('document');
   $uploader->setExtensionPolicy(['pdf', 'doc', 'docx'], ['php', 'phtml', 'phar']);
   $uploader->setStrictContentTypeValidation(true);

   $finalPath = $uploader->processUpload($_FILES['file']);

Resumable chunk flow:

.. code-block:: php

   $state = $uploader->processChunkUpload(
       chunkFile: $_FILES['chunk'],
       uploadId: 'session-42',
       chunkIndex: 0,
       totalChunks: 4,
       originalFilename: 'video.mp4',
   );

   if ($state->complete) {
       $finalPath = $uploader->finalizeChunkUpload('session-42');
   }

``processChunkUpload()`` stores one chunk and returns ``ChunkUploadState``; it
never publishes the final file implicitly. Call ``finalizeChunkUpload()`` only
after ``$state->complete`` is true. Hash naming is calculated from the fully
assembled object, so identical uploads reuse the same deterministic target.

Trusted non-HTTP ingestion:

.. code-block:: php

   $finalPath = $uploader->ingestFile([
       'error' => UPLOAD_ERR_OK,
       'size' => filesize('/srv/import/report.pdf'),
       'tmp_name' => '/srv/import/report.pdf',
       'name' => 'report.pdf',
   ]);

Hardened chunk upload:

.. code-block:: php

   $uploader->setChunkLimits(maxChunkCount: 20, maxChunkSize: 2 * 1024 * 1024); // 2MB
   $uploader->setRequireMalwareScan(true);
   $uploader->setMalwareScanner(new ClamScanner());

   $uploader->processChunkUpload(
       chunkFile: $_FILES['chunk'],
       uploadId: 'session_42',
       chunkIndex: 0,
       totalChunks: 4,
       originalFilename: 'video.mp4',
   );

Mounted destination example:

.. code-block:: php

   use Infocyph\Pathwise\Storage\StorageFactory;
   use Infocyph\Pathwise\StreamHandler\UploadProcessor;

   StorageFactory::mount('s3', ['adapter' => $myS3Adapter]);

   $uploader = new UploadProcessor();
   $uploader->setDirectorySettings('s3://uploads', false, 's3://tmp');
   $uploader->setValidationProfile('document');

   $finalPath = $uploader->processUpload($_FILES['file']);
