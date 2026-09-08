Recipes
=======

Short Pathwise 4 workflow examples. These intentionally preserve the boundary
between direct-local OS capabilities and instance-scoped Flysystem storage.

Recipe 1: Framework Upload -> Scan -> Object Storage
----------------------------------------------------

.. code-block:: php

   use Infocyph\Pathwise\Storage\StorageContext;
   use Infocyph\Pathwise\StreamHandler\MalwareScanMode;
   use Infocyph\Pathwise\StreamHandler\UploadProcessor;
   use Infocyph\Pathwise\StreamHandler\UploadSource;

   $storage = new StorageContext([
       'objects' => ['filesystem' => $objectFilesystem],
   ], 'objects');

   $uploader = new UploadProcessor();
   $uploader->setStorageContext($storage);
   $uploader->setDirectorySettings('objects://uploads', tempDir: sys_get_temp_dir());
   $uploader->setValidationProfile('document');
   $uploader->setMalwareScanner($scanner);
   $uploader->setMalwareScanMode(MalwareScanMode::REQUIRED);

   $source = UploadSource::fromMover(
       fn (string $target): void => $uploadedFile->moveTo($target),
       $uploadedFile->getClientFilename() ?? 'upload.bin',
       $uploadedFile->getSize(),
       $uploadedFile->getClientMediaType(),
       $uploadedFile->getError(),
   );

   $finalPath = $uploader->ingestSource($source);

The framework does not create a synthetic ``$_FILES`` record. Pathwise owns the
local staging copy and scanner lifecycle, while the destination remains an
instance-scoped object filesystem.

Recipe 2: Prepare a Framework Download
--------------------------------------

.. code-block:: php

   use Infocyph\Pathwise\StreamHandler\DownloadProcessor;

   $downloads = new DownloadProcessor();
   $downloads->setStorageContext($storage);
   $downloads->setAllowedRoots(['objects://downloads']);

   $prepared = $downloads->prepareDownload(
       'objects://downloads/report.pdf',
       'report.pdf',
       $requestRange,
   );

   // Framework owns the response object.
   $status = $prepared->status;
   $headers = $prepared->headers;
   $body = $downloads->streamChunks($prepared);

The body iterator is range-aware and closes its source if the consumer stops
early.

Recipe 3: Local Mirror -> Archive -> Verify
-------------------------------------------

.. code-block:: php

   use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;
   use Infocyph\Pathwise\FileManager\FileOperations;

   $source = new DirectoryOperations('/tmp/project-output');
   $sync = $source->syncTo('/tmp/project-backup', deleteOrphans: true);
   $source->zip('/tmp/project-output.zip');

   $archive = new FileOperations('/tmp/project-output.zip');
   $expected = hash_file('sha256', '/tmp/project-output.zip');
   $verified = is_string($expected)
       && $archive->verifyChecksum($expected, 'sha256');

Archive creation/extraction applies Pathwise's archive-entry validation and
configured safety limits. Native optimization remains bounded and falls back
only where the operation contract permits it.

Recipe 4: Audit -> Retain
-------------------------

.. code-block:: php

   use Infocyph\Pathwise\Observability\AuditTrail;
   use Infocyph\Pathwise\Retention\RetentionManager;

   $audit = new AuditTrail('/var/log/app/pathwise.jsonl');
   $audit->log('artifact.published', ['path' => $finalPath]);

   $retention = RetentionManager::apply(
       '/srv/app/artifacts',
       keepLast: 50,
       maxAgeDays: 30,
   );

   $audit->log('retention.applied', [
       'deleted' => $retention->deleted,
       'kept' => $retention->kept,
   ]);

For high-volume audit output, use ``PartitionedAuditSink`` to bound individual
files instead of treating one unbounded JSONL file as a log service.

Recipe 5: File Queue Lease Worker
---------------------------------

.. code-block:: php

   use Infocyph\Pathwise\Queue\FileJobQueue;

   $queue = new FileJobQueue('/var/lib/app/work.json');
   $queue->enqueue('render', ['asset' => '42'], priority: 10);

   while (($reservation = $queue->reserve()) !== null) {
       try {
           render($reservation->payload);
           $queue->acknowledge($reservation);
       } catch (Throwable $failure) {
           $queue->fail($reservation, $failure);
       }
   }

For long-running work, call ``renew()`` before the reservation timeout. A stale
reservation cannot acknowledge/release/fail a job after another worker has
acquired a new lease.

Recipe 6: Duplicate Scan and Local Dedupe
-----------------------------------------

.. code-block:: php

   use Infocyph\Pathwise\Indexing\ChecksumIndexer;

   $duplicates = ChecksumIndexer::findDuplicates('/srv/media', 'sha256');

   if ($duplicates !== []) {
       $result = ChecksumIndexer::deduplicateWithHardLinks('/srv/media', 'sha256');
   }

Hard-link deduplication is intentionally local and filesystem-dependent. Treat
``DeduplicationResult::skipped`` as an expected portability signal rather than
assuming every platform/filesystem can link every candidate.

Recipe 7: Two Applications, Same Logical Disk Name
--------------------------------------------------

.. code-block:: php

   $appA = new StorageContext([
       'files' => ['driver' => 'local', 'root' => '/srv/app-a'],
   ], 'files');

   $appB = new StorageContext([
       'files' => ['driver' => 'local', 'root' => '/srv/app-b'],
   ], 'files');

   $uploadA->setStorageContext($appA);
   $uploadB->setStorageContext($appB);

   $uploadA->setDirectorySettings('files://uploads');
   $uploadB->setDirectorySettings('files://uploads');

Both processors use ``files://uploads`` without sharing operators, roots, or
process-global registration.
