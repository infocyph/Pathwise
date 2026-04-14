Unified File Facade
===================

Namespace: ``Infocyph\Pathwise``

Pathwise provides ``File`` as a convenience facade when you want one entry
point instead of importing many classes directly.

Use this when:

* you want path-bound access to file/directory/compression/read/write APIs
* you want static gateways for upload/download/storage/policy/queue/audit/etc.

Keep direct classes when:

* you prefer explicit class-level imports for large codebases
* you need very focused dependencies per module

Path-Bound Access
-----------------

.. code-block:: php

   use Infocyph\Pathwise\File;

   $entry = File::at('/tmp/demo.txt');

   $entry->file()->create('hello')->append("\nworld");

   $reader = $entry->reader();
   foreach ($reader->line() as $line) {
       // ...
   }

   $writer = $entry->writer(true);
   $writer->line('tail');
   $writer->close();

   $metadata = $entry->metadata();

Directory + Compression via Same Entry
--------------------------------------

.. code-block:: php

   use Infocyph\Pathwise\File;

   File::at('/tmp/source')->directory()->create();

   File::at('/tmp/archive.zip')
       ->compression(true)
       ->compress('/tmp/source')
       ->save();

Static Gateways
---------------

.. code-block:: php

   use Infocyph\Pathwise\File;

   $upload = File::upload();
   $download = File::download();
   $policy = File::policy();
   $queue = File::queue('/tmp/jobs.json');
   $audit = File::audit('/tmp/audit.jsonl');

Storage from Facade
-------------------

``File`` delegates storage creation/mounting to ``StorageFactory``.

.. code-block:: php

   use Infocyph\Pathwise\File;

   File::mountStorage('assets', [
       'driver' => 'local',
       'root' => '/srv/storage/assets',
   ]);

   // For other adapters, pass adapter/constructor config:
   // File::mountStorage('s3', ['driver' => 's3', 'adapter' => $adapter]);

Operational Tooling from Facade
-------------------------------

Available helpers:

* ``File::retain(...)`` -> ``RetentionManager``
* ``File::index(...)`` / ``File::duplicates(...)`` / ``File::deduplicate(...)`` -> ``ChecksumIndexer``
* ``File::snapshot(...)`` / ``File::diffSnapshots(...)`` / ``File::watch(...)`` -> ``FileWatcher``

See also:

* ``storage-adapters`` for adapter bootstrap
* ``upload-processing`` and ``download-processing`` for stream workflows
