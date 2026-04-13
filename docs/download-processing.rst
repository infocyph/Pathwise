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
* Stream copy to caller-provided output resource.
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

   // Use $manifest['status'] and $manifest['headers'] in your framework response.

Stream output with range support:

.. code-block:: php

   $output = fopen('php://output', 'wb');

   $manifest = $downloads->streamDownload(
       path: '/srv/app/downloads/video.mp4',
       outputStream: $output,
       downloadName: 'video.mp4',
       rangeHeader: $_SERVER['HTTP_RANGE'] ?? null,
   );

   // $manifest includes status, headers, rangeStart/rangeEnd and bytesSent.

Mounted storage example:

.. code-block:: php

   use Infocyph\Pathwise\Storage\StorageFactory;
   use Infocyph\Pathwise\StreamHandler\DownloadProcessor;

   StorageFactory::mount('s3', ['adapter' => $myS3Adapter]);

   $downloads = new DownloadProcessor();
   $downloads->setAllowedRoots(['s3://downloads']);

   $manifest = $downloads->prepareDownload('s3://downloads/report.pdf');
