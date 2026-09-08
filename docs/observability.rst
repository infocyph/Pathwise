Observability
=============

Namespace: ``Infocyph\Pathwise\Observability``

``AuditTrail`` delegates structured operation records to an ``AuditSink``.
Pathwise deliberately separates local append durability from remote/application
transport semantics instead of pretending every storage backend supports the
same append primitive.

Audit Sinks
-----------

``LocalJsonlAuditSink``
   Direct-local line-delimited JSON. Writes use an exclusive lock, full-write
   handling and flush/synchronization of committed state. Pathwise-owned local
   audit state is private where the platform exposes POSIX permissions.

``PartitionedAuditSink``
   Stores separate bounded event objects/segments on writable storage. Prefer
   this shape for adapter-backed/object storage because it avoids hidden
   read-modify-write append of a complete remote object.

``CallbackAuditSink``
   Forwards structured events into an application logger, collector, message
   pipeline or other application-owned observability system.

Typical event context may include operation, path/source/destination,
bytes/checksum/visibility and caller-provided metadata. Consumers should treat
that context as audit data, not as an authorization decision.

Example
-------

.. code-block:: php

   use Infocyph\Pathwise\FileManager\FileOperations;
   use Infocyph\Pathwise\Observability\AuditTrail;

   $audit = new AuditTrail('/tmp/pathwise-audit.jsonl');

   (new FileOperations('/tmp/a.txt'))
       ->setAuditTrail($audit)
       ->create('hello')
       ->append("\nworld");

Storage Boundary
----------------

A logical/adapter-backed path cannot be passed as though it were a native JSONL
append target. Portable Flysystem does not define atomic append/locking.
Choose a partitioned or callback sink for those workloads; Pathwise does not
hide a complete remote audit-object rewrite behind a local-looking API.

For long-running services, bound partition/event size according to the selected
sink and ship/rotate audit state through application operations. See
:doc:`storage-contracts` and :doc:`performance-portability`.
