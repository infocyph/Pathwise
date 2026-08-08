Observability
=============

Namespace: ``Infocyph\Pathwise\Observability``

``AuditTrail`` delegates records to an ``AuditSink``.

Brief capabilities:

* Log timestamped operation events with context.
* ``LocalJsonlAuditSink`` stores line-delimited JSON with locked native append.
* ``PartitionedAuditSink`` stores one event object per event on any writable mount.
* ``CallbackAuditSink`` forwards records to an application logger or collector.
* Integrate with ``FileOperations`` to trace file lifecycle actions.

Typical fields:

* operation name
* path/source/destination
* bytes/checksum/visibility context

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

Mounted paths cannot be passed as the local JSONL sink because portable remote
append does not exist. Use a partitioned or callback sink; Pathwise never hides
a complete remote audit-object rewrite.
