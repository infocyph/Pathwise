Quickstart
==========

This page shows the recommended Pathwise 4 entry points. Persistent storage
configuration is instance-scoped; the facade remains stateless convenience.

1) Install
----------

.. code-block:: bash

   composer require infocyph/pathwise:^4.0

2) Basic File Lifecycle
-----------------------

.. code-block:: php

   use Infocyph\Pathwise\PathwiseFacade;

   $file = PathwiseFacade::at('/tmp/example.txt')->file();
   $file->create("v1\n")
       ->append("v2\n")
       ->writeAndVerify("v3\n", 'sha256');

   $content = $file->read();

3) Create an Instance-Scoped Storage Context
--------------------------------------------

.. code-block:: php

   use Infocyph\Pathwise\Storage\StorageContext;

   $storage = new StorageContext([
       'assets' => [
           'driver' => 'local',
           'root' => '/srv/storage/assets',
       ],
       'archive' => [
           'driver' => 'local',
           'root' => '/srv/storage/archive',
       ],
   ], 'assets');

   [$filesystem, $location] = $storage->resolve('reports/a.txt');
   $filesystem->write($location, "hello\n");

   [$archive, $archivePath] = $storage->resolve('archive://2026/a.txt');
   $archive->write($archivePath, "archived\n");

No global mount is registered. Two contexts may safely reuse the same logical
filesystem names with different roots/operators.

4) Directory Sync with a Typed Report
-------------------------------------

.. code-block:: php

   use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;

   $source = new DirectoryOperations('/tmp/source');
   $report = $source->syncTo('/tmp/backup', deleteOrphans: true);

   foreach ($report->created as $path) {
       // newly-created entry
   }

5) Framework-Neutral Upload
---------------------------

.. code-block:: php

   use Infocyph\Pathwise\StreamHandler\UploadProcessor;
   use Infocyph\Pathwise\StreamHandler\UploadSource;

   $uploader = new UploadProcessor();
   $uploader->setStorageContext($storage);
   $uploader->setDirectorySettings('assets://uploads', tempDir: sys_get_temp_dir());
   $uploader->setValidationProfile('document');

   $source = UploadSource::fromStream(
       stream: $inputStream,
       clientFilename: 'report.pdf',
       size: $knownSize,
       clientMediaType: 'application/pdf',
   );

   $finalPath = $uploader->ingestSource($source);

For a real PHP HTTP upload, ``processUpload($_FILES['file'])`` preserves the
``is_uploaded_file()`` provenance check. ``UploadSource`` is the preferred
framework-neutral bridge for uploaded-file abstractions, streams, and paths.

6) Resumable Chunk Upload
-------------------------

.. code-block:: php

   $state = $uploader->processChunkUploadSource(
       source: $chunkSource,
       uploadId: 'session-42',
       chunkIndex: 0,
       totalChunks: 3,
       originalFilename: 'video.mp4',
   );

   if ($state->complete) {
       $finalPath = $uploader->finalizeChunkUpload('session-42');
   }

``ChunkUploadState`` is a typed result; final publication happens only through
``finalizeChunkUpload()``.

7) Prepare and Stream a Download
--------------------------------

.. code-block:: php

   use Infocyph\Pathwise\StreamHandler\DownloadProcessor;

   $downloads = new DownloadProcessor();
   $downloads->setStorageContext($storage);
   $downloads->setAllowedRoots(['assets://downloads']);

   $prepared = $downloads->prepareDownload(
       path: 'assets://downloads/video.mp4',
       rangeHeader: $_SERVER['HTTP_RANGE'] ?? null,
   );

   foreach ($downloads->streamChunks($prepared) as $chunk) {
       echo $chunk;
   }

Use ``$prepared->status``, ``$prepared->headers`` and ``$prepared->range`` when
bridging the metadata to an HTTP framework.

8) Observability and Policy
---------------------------

.. code-block:: php

   use Infocyph\Pathwise\FileManager\FileOperations;
   use Infocyph\Pathwise\Observability\AuditTrail;
   use Infocyph\Pathwise\Security\PolicyEngine;

   $policy = (new PolicyEngine())
       ->allow('*', '*')
       ->deny('delete', '/tmp/protected/*');

   $audit = new AuditTrail('/tmp/pathwise-audit.jsonl');

   (new FileOperations('/tmp/data.txt'))
       ->setPolicyEngine($policy)
       ->setAuditTrail($audit)
       ->create('hello');

Read :doc:`storage-context`, :doc:`upload-processing`,
:doc:`download-processing`, :doc:`security`, and :doc:`migration-4.0` next.
