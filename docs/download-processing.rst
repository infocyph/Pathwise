Download Processing
===================

Namespace: ``Infocyph\Pathwise\StreamHandler``

``DownloadProcessor`` separates secure download preparation from body delivery.
That makes it suitable for framework adapters without making Pathwise an HTTP
response implementation.

Core Flow
---------

1. Configure storage and policy.
2. Call ``prepareDownload()`` to validate the path and produce typed metadata.
3. Bridge ``DownloadPreparation::status`` and ``headers`` to the framework.
4. Stream exactly that preparation through ``streamChunks()`` or use
   ``streamDownload()`` for a writable PHP resource.

.. code-block:: php

   use Infocyph\Pathwise\StreamHandler\DownloadProcessor;

   $downloads = new DownloadProcessor();
   $downloads->setStorageContext($storage);
   $downloads->setAllowedRoots(['objects://downloads']);
   $downloads->setExtensionPolicy(['pdf', 'zip', 'mp4']);

   $prepared = $downloads->prepareDownload(
       path: 'objects://downloads/video.mp4',
       downloadName: 'video.mp4',
       rangeHeader: $_SERVER['HTTP_RANGE'] ?? null,
   );

   foreach ($downloads->streamChunks($prepared) as $chunk) {
       echo $chunk;
   }

StorageContext Integration
--------------------------

``setStorageContext()`` gives the processor an instance-owned resolver. Configure
it before path/root policy.

* direct absolute paths remain local;
* relative paths use the context default filesystem;
* ``name://path`` selects a configured context filesystem;
* no global mount is registered.

The same logical storage name can therefore be used by processors belonging to
different applications in one process without cross-talk.

Security Controls
-----------------

``DownloadProcessor`` exposes:

* ``setAllowedRoots(array $roots)``;
* ``setExtensionPolicy(array $allowedExtensions = [], array $blockedExtensions = [])``;
* ``setBlockHiddenFiles(bool $block = true)``;
* ``setMaxDownloadSize(int $maxDownloadSize = 0)``;
* ``setRangeRequestsEnabled(bool $enabled = true)``;
* ``setForceAttachment(bool $enabled = true)``;
* ``setDefaultDownloadName(string $name)``;
* ``setChunkSize(int $chunkSize)``.

Hidden files are blocked by default. The default blocked extension set covers
common executable/server-side script types. Allowed-root checks compare paths
inside the same resolved filesystem; a path on another context filesystem
cannot satisfy a root merely by sharing a textual prefix.

DownloadPreparation
-------------------

``prepareDownload()`` returns ``DownloadPreparation`` containing:

* canonical Pathwise path;
* safe download filename;
* MIME type;
* size and last-modified metadata;
* weak ETag;
* status (``200`` or ``206``);
* ``RangeDownloadMetadata``;
* response-oriented headers.

Headers include safe ``Content-Disposition``, ``Content-Length``,
``Content-Type``, ``Last-Modified``, ``ETag``, ``Accept-Ranges``,
``Cache-Control`` and ``X-Content-Type-Options``. Framework/application code
still owns conditional request policy such as If-None-Match/If-Modified-Since.

Range Semantics
---------------

Pathwise accepts one byte range in the standard ``bytes=start-end``, open-ended,
or suffix form. Invalid/unsatisfiable ranges raise ``DownloadException`` rather
than silently degrading to a full response.

``RangeDownloadMetadata`` exposes the resolved start/end, content length, and
partial flag. Empty files have a zero content length and no numeric start/end.

Prepared Streaming and Revalidation
-----------------------------------

``streamChunks(DownloadPreparation $preparation)`` opens the source lazily,
positions seekable streams directly or discards bytes on non-seekable streams,
reads no more than the prepared range, and closes the source in a ``finally``
block. Disposing the generator early therefore closes its input resource.

A preparation is metadata, **not** an authorization capability. Before opening
the body Pathwise re-applies current path/root/hidden-file/extension/max-size
policy and verifies that current size/last-modified metadata still matches the
preparation. Stale or manually-constructed preparations cannot bypass policy.

.. code-block:: php

   $prepared = $downloads->prepareDownload(
       '/srv/app/downloads/report.pdf',
       'monthly-report.pdf',
   );

   foreach ($downloads->streamChunks($prepared) as $chunk) {
       // yield/write chunk to your framework response
   }

Direct Output Stream
--------------------

``streamDownload()`` shares the same preparation and chunk-streaming core:

.. code-block:: php

   $output = fopen('php://output', 'wb');

   $result = $downloads->streamDownload(
       path: '/srv/app/downloads/video.mp4',
       outputStream: $output,
       downloadName: 'video.mp4',
       rangeHeader: $_SERVER['HTTP_RANGE'] ?? null,
   );

   // $result->preparation
   // $result->bytesSent

Writes are completed fully or fail with ``DownloadException``; partial
``fwrite()`` results are retried until the current chunk is complete.

Framework Boundary
------------------

Pathwise owns filesystem/range mechanics. An HTTP framework owns request
conditionals, response object creation, server offload features such as
X-Sendfile/X-Accel-Redirect, and connection lifecycle. This boundary is
intentional and is the integration model used by Foundation 3.

See :doc:`storage-context`, :doc:`security`, and
:doc:`performance-portability`.
