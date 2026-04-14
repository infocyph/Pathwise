Quickstart
==========

This quickstart shows the fastest way to understand what Pathwise can do.

1) Install
-----------

.. code-block:: bash

   composer require infocyph/pathwise

2) Basic File Lifecycle
-----------------------

.. code-block:: php

   use Infocyph\Pathwise\File;

   $file = File::at('/tmp/example.txt')->file();
   $file->create("v1\n")
       ->append("v2\n")
       ->writeAndVerify("v3\n", 'sha256');

   $content = $file->read();

3) Mount a Storage and Use Scheme Paths
---------------------------------------

.. code-block:: php

   use Infocyph\Pathwise\Storage\StorageFactory;
   use Infocyph\Pathwise\Utils\FlysystemHelper;

   StorageFactory::mount('assets', [
       'driver' => 'local',
       'root' => '/srv/storage/assets',
   ]);

   FlysystemHelper::write('assets://reports/a.txt', "hello\n");
   $text = FlysystemHelper::read('assets://reports/a.txt');

4) Directory Sync with Diff Report
----------------------------------

.. code-block:: php

   use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;

   $source = new DirectoryOperations('/tmp/source');
   $report = $source->syncTo('/tmp/backup', deleteOrphans: true);

   // $report has created/updated/deleted entries

5) Upload Validation and Chunk Finalization
-------------------------------------------

.. code-block:: php

   use Infocyph\Pathwise\StreamHandler\UploadProcessor;

   $uploader = new UploadProcessor();
   $uploader->setDirectorySettings('/tmp/uploads');
   $uploader->setValidationProfile('document');

   // Single upload:
   // $finalPath = $uploader->processUpload($_FILES['file']);

   // Chunked:
   $state = $uploader->processChunkUpload(
       chunkFile: $_FILES['chunk'],
       uploadId: 'session-42',
       chunkIndex: 0,
       totalChunks: 3,
       originalFilename: 'video.mp4',
   );

   if ($state['isComplete']) {
       $finalPath = $uploader->finalizeChunkUpload('session-42');
   }

6) Compression with Filters + Progress
--------------------------------------

.. code-block:: php

   use Infocyph\Pathwise\FileManager\FileCompression;

   $zip = new FileCompression('/tmp/out.zip', true);
   $zip->setGlobPatterns(includePatterns: ['*.txt'], excludePatterns: ['*.tmp'])
       ->setProgressCallback(function (array $event): void {
           // operation, path, current, total
       })
       ->compress('/tmp/source')
       ->save();

7) Observability and Guardrails
-------------------------------

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
