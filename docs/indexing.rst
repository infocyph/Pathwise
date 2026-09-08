Indexing
========

Namespace: ``Infocyph\Pathwise\Indexing``

``ChecksumIndexer`` supports checksum iteration, complete checksum indexes,
duplicate detection, and direct-local hard-link deduplication.

Capabilities
------------

``iterate()``
   Streams ``checksum``/``path`` pairs without retaining the complete index in
   memory. Prefer this API for large inventory/integrity workloads.

``buildIndex()``
   Materializes a checksum-to-path-list map. Memory therefore grows with the
   number of indexed files.

``findDuplicates()``
   Materializes the complete index and returns checksum groups containing more
   than one path.

``deduplicateWithHardLinks()``
   Uses duplicate groups to replace verified direct-local duplicates with hard
   links where safe/supported. Adapter-backed targets are skipped rather than
   being treated as native files.

SHA-256 is the default because checksum indexing is also used for
integrity-oriented workflows. A caller that only needs a non-security
fingerprint may explicitly select another algorithm supported by PHP.

Streaming Example
-----------------

.. code-block:: php

   use Infocyph\Pathwise\Indexing\ChecksumIndexer;

   foreach (ChecksumIndexer::iterate('/tmp/assets', 'sha256') as $entry) {
       printf("%s  %s\n", $entry['checksum'], $entry['path']);
   }

Duplicate Example
-----------------

.. code-block:: php

   use Infocyph\Pathwise\Indexing\ChecksumIndexer;

   $duplicates = ChecksumIndexer::findDuplicates('/tmp/assets', 'sha256');
   $result = ChecksumIndexer::deduplicateWithHardLinks('/tmp/assets', 'sha256');

   foreach ($result->linked as $path) {
       // The verified duplicate was replaced by a hard link.
   }

Hashing adapter-backed content can require a complete remote read per object.
Measure checksum workflows against realistic storage latency/request costs. See
:doc:`performance-portability` for the Pathwise 4 release workload guidance.
