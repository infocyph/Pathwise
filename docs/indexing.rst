Indexing
========

Namespace: ``Infocyph\Pathwise\Indexing``

``ChecksumIndexer`` builds and uses checksum maps for directories.

Brief capabilities:

* Build checksum index per file.
* Detect duplicate files by hash.
* Attempt deduplication workflows (for example hard-link strategy where supported).

Use cases:

* Duplicate detection.
* Content-based integrity scans.
* Pre-cleanup analysis for storage optimization.

Example
-------

.. code-block:: php

   use Infocyph\Pathwise\Indexing\ChecksumIndexer;

   $index = ChecksumIndexer::buildIndex('/tmp/assets', 'sha256');
   $duplicates = ChecksumIndexer::findDuplicates('/tmp/assets', 'sha256');
