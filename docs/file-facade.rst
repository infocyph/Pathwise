Unified Pathwise Facade
=======================

Namespace: ``Infocyph\Pathwise``

Pathwise provides ``PathwiseFacade`` as a convenience facade when you want one entry
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

   use Infocyph\Pathwise\PathwiseFacade;

   $entry = PathwiseFacade::at('/tmp/demo.txt');

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

   use Infocyph\Pathwise\PathwiseFacade;

   PathwiseFacade::at('/tmp/source')->directory()->create();

   PathwiseFacade::at('/tmp/archive.zip')
       ->compression(true)
       ->compress('/tmp/source')
       ->save();

Static Gateways
---------------

.. code-block:: php

   use Infocyph\Pathwise\PathwiseFacade;

   $upload = PathwiseFacade::upload();
   $download = PathwiseFacade::download();
   $policy = PathwiseFacade::policy();
   $queue = PathwiseFacade::queue('/tmp/jobs.json');
   $audit = PathwiseFacade::audit('/tmp/audit.jsonl');

Storage from Facade
-------------------

``PathwiseFacade`` delegates storage creation/mounting to ``StorageFactory``.

.. code-block:: php

   use Infocyph\Pathwise\PathwiseFacade;

   PathwiseFacade::mountStorage('assets', [
       'driver' => 'local',
       'root' => '/srv/storage/assets',
   ]);

   // For other adapters, pass adapter/constructor config:
   // PathwiseFacade::mountStorage('s3', ['driver' => 's3', 'adapter' => $adapter]);

Operational Tooling from Facade
-------------------------------

Available helpers:

* ``PathwiseFacade::retain(...)`` -> ``RetentionManager``
* ``PathwiseFacade::index(...)`` / ``PathwiseFacade::duplicates(...)`` / ``PathwiseFacade::deduplicate(...)`` -> ``ChecksumIndexer``
* ``PathwiseFacade::snapshot(...)`` / ``PathwiseFacade::diffSnapshots(...)`` / ``PathwiseFacade::watch(...)`` -> ``FileWatcher``

See also:

* ``storage-adapters`` for adapter bootstrap
* ``upload-processing`` and ``download-processing`` for stream workflows
