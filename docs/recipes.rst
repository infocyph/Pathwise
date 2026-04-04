Recipes
=======

Short end-to-end examples for common real workloads.

Recipe 1: Ingest -> Audit -> Retain
-----------------------------------

Goal:

* Validate uploads.
* Record operations.
* Keep only latest artifacts.

.. code-block:: php

   use Infocyph\Pathwise\Observability\AuditTrail;
   use Infocyph\Pathwise\Retention\RetentionManager;
   use Infocyph\Pathwise\StreamHandler\UploadProcessor;

   $uploader = new UploadProcessor();
   $uploader->setDirectorySettings('/tmp/uploads');
   $uploader->setValidationProfile('document');

   $audit = new AuditTrail('/tmp/audit.jsonl');

   $finalPath = $uploader->processUpload($_FILES['file']);
   $audit->log('upload.processed', ['path' => $finalPath]);

   $retention = RetentionManager::apply('/tmp/uploads', keepLast: 50, maxAgeDays: 30);
   $audit->log('retention.applied', $retention);

Recipe 2: Mirror + Zip + Checksum
---------------------------------

Goal:

* Mirror a working directory to backup.
* Create archive.
* Verify archive checksum.

.. code-block:: php

   use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;
   use Infocyph\Pathwise\FileManager\FileOperations;

   $source = new DirectoryOperations('/tmp/project-output');
   $syncReport = $source->syncTo('/tmp/project-backup', deleteOrphans: true);

   $source->zip('/tmp/project-output.zip');

   $zipFile = new FileOperations('/tmp/project-output.zip');
   $expected = hash('sha256', $zipFile->read());
   $isValid = $zipFile->verifyChecksum($expected, 'sha256');

Recipe 3: Duplicate Scan + Optional Dedupe
------------------------------------------

Goal:

* Find duplicate files by content hash.
* Optionally deduplicate via hard links where possible.

.. code-block:: php

   use Infocyph\Pathwise\Indexing\ChecksumIndexer;

   $duplicates = ChecksumIndexer::findDuplicates('/tmp/media', 'sha256');

   if ($duplicates !== []) {
       $dedupeReport = ChecksumIndexer::deduplicateWithHardLinks('/tmp/media');
       // linked[] and skipped[] in report
   }

Recipe 4: Mounted Storage Workflow
----------------------------------

Goal:

* Work against mounted storage with the same Pathwise APIs.

.. code-block:: php

   use Infocyph\Pathwise\FileManager\FileCompression;
   use Infocyph\Pathwise\Utils\FlysystemHelper;
   use League\Flysystem\Filesystem;
   use League\Flysystem\Local\LocalFilesystemAdapter;

   FlysystemHelper::mount('mnt', new Filesystem(
       new LocalFilesystemAdapter('/srv/storage')
   ));

   FlysystemHelper::write('mnt://source/a.txt', 'A');
   FlysystemHelper::write('mnt://source/b.txt', 'B');

   (new FileCompression('mnt://archives/source.zip', true))
       ->compress('mnt://source')
       ->save();

   (new FileCompression('mnt://archives/source.zip'))
       ->decompress('mnt://restored');
