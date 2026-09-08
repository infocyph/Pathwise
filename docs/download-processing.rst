Download Processing
===================

Namespace: ``Infocyph\Pathwise\StreamHandler``

Where it fits:

* Use this module when you need secure download metadata and controlled stream
  delivery for local or mounted filesystems.

``DownloadProcessor`` supports:

* Download metadata generation with headers suitable for HTTP adapters.
* Safe download filename handling for ``Content-Disposition``.
* Extension allowlist/blocklist controls.
* Allowed-root restrictions to prevent serving files outside trusted paths.
* Hidden-file blocking.
* Optional max download size enforcement.
* Optional range requests with byte-range parsing and partial metadata.
* Range-aware iterable chunk streaming for framework response adapters.
* Stream copy to caller-provided output resources.
* Mounted/default filesystem paths (e.g. ``s3://...``) via Flysystem routing.

Security controls
-----------------

``DownloadProcessor`` exposes explicit hardening options:

* ``setAllowedRoots(array $roots)``
* ``setExtensionPolicy(array $allowedExtensions = [], array $blockedExtensions = [])``
* ``setBlockHiddenFiles(bool $block = true)``
* ``setMaxDownloadSize(int $maxDownloadSize = 0)``
* ``setRangeRequestsEnabled(bool $enabled = true)``
* ``setForceAttachment(bool $enabled = true)``
* ``setDefaultDownloadName(string $name)``
* ``setChunkSize(int $chunkSize)``

Prepared Chunk Streaming
------------------------

Frameworks that own their HTTP response lifecycle can prepare headers/status
first and then hand the exact prepared range back to Pathwise for body delivery:

.. code-block:: php

   $manifest = $downloads->prepareDownload(
       path: 's3://downloads/video.mp4',
       downloadName: 'video.mp4',
       rangeHeader: $rangeHeader,
   );

   foreach ($downloads->streamChunks($manifest) as $chunk) {
       yield $chunk;
   }

``streamChunks()`` opens the source lazily, positions seekable or non-seekable
streams at the prepared range start, limits reads to the prepared content
length, and closes the input stream in a ``finally`` block. Disposing a
partially-consumed generator therefore releases its input resource.

A ``DownloadPreparation`` is metadata, not an authorization capability. Before
opening its body, Pathwise re-applies current path/hidden-file/allowed-root and
extension policy, checks the current maximum-size policy, and verifies that the
source size and last-modified metadata still match the preparation. Stale or
manually constructed preparations cannot use ``streamChunks()`` to bypass the
current download policy.

``streamDownload()`` uses this same chunk-streaming core, so direct output-stream
copies and framework iterable responses share range, incomplete-read, and
resource-cleanup behavior.

Examples
--------

Prepare secure metadata:

.. code-block:: php

   use Infocyph\Pathwise\StreamHandler\DownloadProcessor;

   $downloads = new DownloadProcessor();
   $downloads->setAllowedRoots(['/srv/app/downloads']);
   $downloads->setExtensionPolicy(['pdf', 'zip'], ['php', 'phar', 'exe']);

   $manifest = $downloads->prepareDownload(
       path: '/srv/app/downloads/report.pdf',
       downloadName: 'monthly-report.pdf',
       rangeHeader: null,
   );

   // Use $manifest->status, $manifest->headers, and $manifest->range.

Stream output with range support:

.. code-block:: php

   $output = fopen('php://output', 'wb');

   $result = $downloads->streamDownload(
       path: '/srv/app/downloads/video.mp4',
       outputStream: $output,
       downloadName: 'video.mp4',
       rangeHeader: $_SERVER['HTTP_RANGE'] ?? null,
   );

   // $result->preparation contains status, headers, and range metadata.
   // $result->bytesSent is the number of bytes written to the output stream.

Mounted storage example:

.. code-block:: php

   use Infocyph\Pathwise\Storage\StorageFactory;
   use Infocyph\Pathwise\StreamHandler\DownloadProcessor;

   StorageFactory::mount('s3', ['adapter' => $myS3Adapter]);

   $downloads = new DownloadProcessor();
   $downloads->setAllowedRoots(['s3://downloads']);

   $manifest = $downloads->prepareDownload('s3://downloads/report.pdf');
