Pathwise 3.0
============

Pathwise 3.0 is a direct breaking release for PHP 8.4+. There are no deprecated
aliases or compatibility wrappers.

Migration Summary
-----------------

* Replace reader magic calls with explicit methods such as ``lines()``,
  ``csv()``, and ``jsonLines()``.
* Replace writer magic calls with ``writeLine()``, ``writeCsv()``,
  ``writeJson()``, and the other explicit ``write*`` methods.
* Consume readonly result objects from sync, downloads, chunk uploads, queues,
  native execution, retention, deduplication, and watching.
* Replace global helpers with ``PathwiseFacade`` or the corresponding service.
* Use ``append()`` only for direct local paths. Choose ``appendEmulated()``
  explicitly for adapter-backed object replacement.
* Keep transactions on direct local paths and handle
  ``UnsupportedStorageOperationException`` for local-only capabilities.
* Treat ``ExecutionStrategy::NATIVE`` as strict: it never falls back.

Archive extraction now rejects unsafe entries before any member is written.
Review code that previously expected permissive extraction or boolean command
results.
