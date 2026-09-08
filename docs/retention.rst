Retention
=========

Namespace: ``Infocyph\Pathwise\Retention``

``RetentionManager`` evaluates deterministic count/age cleanup policy for a
directory and returns the typed ``RetentionResult`` with ``deleted`` and
``kept`` lists.

Capabilities
------------

* ``preview()`` returns the exact decision without mutating storage;
* ``apply()`` uses the same decision engine and then deletes the selected files;
* ``keepLast`` preserves the newest N entries according to the selected sort;
* ``maxAgeDays`` removes entries older than the calculated cutoff;
* count and age rules combine with OR semantics for deletion;
* ties are resolved deterministically by path;
* ``mtime`` works for direct-local and adapter-backed listings;
* ``ctime`` is a direct-local-only capability and is rejected for
  adapter-backed storage.

Preview First
-------------

Use ``preview()`` when an operator/application should inspect or audit the exact
cleanup set before mutation:

.. code-block:: php

   use Infocyph\Pathwise\Retention\RetentionManager;

   $preview = RetentionManager::preview(
       directory: '/tmp/backups',
       keepLast: 7,
       maxAgeDays: 30,
       sortBy: 'mtime',
   );

   foreach ($preview->deleted as $candidate) {
       // Report the candidate before applying the same policy.
   }

   $result = RetentionManager::apply(
       directory: '/tmp/backups',
       keepLast: 7,
       maxAgeDays: 30,
       sortBy: 'mtime',
   );

The filesystem may of course change between preview and apply. Pathwise
therefore guarantees policy parity, not a distributed snapshot/isolation
transaction across those two calls.

Scaling
-------

Retention must collect and sort the candidate file set to make deterministic
newest/age decisions. Memory therefore grows with the number of entries in the
selected directory tree. For very large object stores, partition retention
workloads by prefix/time bucket instead of treating one unbounded namespace as a
single retention set. See :doc:`performance-portability`.
