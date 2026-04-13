Overview
========

Why Pathwise
------------

Pathwise gives you a single API surface for common file-system workflows that
normally require stitching many low-level PHP calls together.

It focuses on three layers:

* Storage operations powered by Flysystem.
* Workflow primitives (uploads, compression, sync, validation).
* Operational building blocks (queue, audit, retention, indexing, policy).

Main namespaces:

* ``Infocyph\Pathwise\FileManager``
* ``Infocyph\Pathwise\DirectoryManager``
* ``Infocyph\Pathwise\StreamHandler``
* ``Infocyph\Pathwise\Storage``
* ``Infocyph\Pathwise\Security``
* ``Infocyph\Pathwise\Queue``
* ``Infocyph\Pathwise\Observability``
* ``Infocyph\Pathwise\Indexing``
* ``Infocyph\Pathwise\Retention``
* ``Infocyph\Pathwise\Utils``

Path style support:

* Local absolute/relative paths.
* Mounted Flysystem scheme paths like ``mnt://reports/file.csv``.

Quick Start
-----------

.. code-block:: php

   use Infocyph\Pathwise\FileManager\FileOperations;
   use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;

   (new FileOperations('/tmp/demo.txt'))
       ->create('hello')
       ->append("\nworld");

   $report = (new DirectoryOperations('/tmp/source'))
       ->syncTo('/tmp/backup', deleteOrphans: true);

Read Next
---------

* ``capabilities`` for the full module map.
* ``quickstart`` for copy/paste examples.
* ``recipes`` for end-to-end workflow patterns.
