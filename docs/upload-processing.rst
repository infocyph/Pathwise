Upload Processing
=================

Namespace: ``Infocyph\Pathwise\StreamHandler``

Where it fits:

* Use this module for HTTP uploads that need validation, deterministic naming,
  resumable chunk flow, and optional malware checks.

``UploadProcessor`` supports:

* Standard upload handling with configurable destination strategy.
* Validation profiles: ``image``, ``video``, ``document``.
* MIME and size validation with optional image dimension validation.
* Naming strategies (hash/timestamp).
* Chunked/resumable uploads:
  * ``processChunkUpload()``
  * ``finalizeChunkUpload()``
* Optional malware scanner callback hook.

Storage notes:

* Uses Flysystem operations for chunk manifests and destination writes.
* Supports mounted/default filesystem routing through helper resolution.

Examples
--------

Basic single upload:

.. code-block:: php

   use Infocyph\Pathwise\StreamHandler\UploadProcessor;

   $uploader = new UploadProcessor();
   $uploader->setDirectorySettings('/tmp/uploads');
   $uploader->setValidationProfile('document');

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
