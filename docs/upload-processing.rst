Upload Processing
=================

Namespace: ``Infocyph\Pathwise\StreamHandler``

Where it fits:

* Use this module for HTTP uploads that need validation, deterministic naming,
  resumable chunk flow, and layered upload hardening.

``UploadProcessor`` supports:

* Standard upload handling with configurable destination strategy.
* Validation profiles: ``image``, ``video``, ``document``.
* MIME and size validation with optional image dimension validation.
* Extension allowlist/blocklist policy.
* Naming strategies (hash/timestamp).
* Chunked/resumable uploads:
  * ``processChunkUpload()``
  * ``finalizeChunkUpload()``
* Upload ID safety validation for chunk/session identifiers.
* Strict content checks:
  * extension <> MIME agreement
  * lightweight file signature verification for common formats
* Malware scanner callback hook (optional or required).

Storage notes:

* Uses Flysystem operations for chunk manifests and destination writes.
* Supports mounted/default filesystem routing through helper resolution.

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

   if ($state['isComplete']) {
       $finalPath = $uploader->finalizeChunkUpload('session-42');
   }

Hardened chunk upload:

.. code-block:: php

   $uploader->setChunkLimits(maxChunkCount: 20, maxChunkSize: 2 * 1024 * 1024); // 2MB
   $uploader->setRequireMalwareScan(true);
   $uploader->setMalwareScanner(
       fn (string $path, string $type): bool => true // return false to block
   );

   $uploader->processChunkUpload(
       chunkFile: $_FILES['chunk'],
       uploadId: 'session_42',
       chunkIndex: 0,
       totalChunks: 4,
       originalFilename: 'video.mp4',
   );
