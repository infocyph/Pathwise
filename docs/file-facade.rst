Unified Pathwise Facade
=======================

Namespace: ``Infocyph\Pathwise``

``PathwiseFacade`` is a **stateless convenience facade** in Pathwise 4. It is
useful for compact direct-local operations and factory-style access to common
workflow objects. Persistent storage topology belongs to
``Storage\StorageContext`` instead.

Path-Bound Access
-----------------

.. code-block:: php

   use Infocyph\Pathwise\PathwiseFacade;

   $entry = PathwiseFacade::at('/tmp/demo.txt');

   $entry->file()->create('hello')->append("\nworld");

   foreach ($entry->reader()->lines() as $line) {
       // ...
   }

   $writer = $entry->writer(true);
   $writer->writeLine('tail');
   $writer->close();

   $metadata = $entry->metadata();

Path-bound methods include ``file()``, ``directory()``, ``compression()``,
``reader()``, ``writer()``, ``exists()``, ``metadata()``, ``mimeType()`` and
``path()``.

Directory + Compression
-----------------------

.. code-block:: php

   PathwiseFacade::at('/tmp/source')->directory()->create();

   PathwiseFacade::at('/tmp/archive.zip')
       ->compression(true)
       ->compress('/tmp/source')
       ->save();

Static Convenience
------------------

.. code-block:: php

   $upload = PathwiseFacade::upload();
   $download = PathwiseFacade::download();
   $policy = PathwiseFacade::policy();
   $queue = PathwiseFacade::queue('/tmp/jobs.json');
   $audit = PathwiseFacade::audit('/tmp/audit.jsonl');

Other stateless helpers include:

* ``createFilesystem(array $config)`` — delegates to ``StorageFactory``;
* ``retain(...)`` — retention;
* ``index(...)``, ``duplicates(...)``, ``deduplicate(...)`` — checksum indexer;
* ``snapshot(...)``, ``diffSnapshots(...)``, ``watch(...)`` — watcher helpers.

Persistent Storage Is Not Facade State
--------------------------------------

Pathwise 4 deliberately removed facade/global storage-mount gateways. Do not
store application topology in ``PathwiseFacade``.

.. code-block:: php

   use Infocyph\Pathwise\Storage\StorageContext;

   $storage = new StorageContext([
       'files' => ['driver' => 'local', 'root' => '/srv/app/files'],
   ], 'files');

   $uploader = PathwiseFacade::upload();
   $uploader->setStorageContext($storage);
   $uploader->setDirectorySettings('files://uploads');

Use direct module classes instead of the facade when explicit constructor/
dependency injection makes the application architecture clearer.

See :doc:`storage-context`, :doc:`storage-adapters`,
:doc:`upload-processing` and :doc:`download-processing`.
