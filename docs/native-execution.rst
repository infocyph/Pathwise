Native Execution
================

Namespaces: ``Infocyph\Pathwise\Core`` and ``Infocyph\Pathwise\Native``

Pathwise can use OS-native commands for selected direct-local workflows through
``ExecutionStrategy``:

* ``PHP`` — force the portable PHP implementation and never start a native tool;
* ``AUTO`` — use a supported native capability when available, otherwise fall
  back to PHP;
* ``NATIVE`` — require the native capability and fail explicitly when it is not
  available or execution fails.

``NativeOperationsAdapter`` remains Pathwise's filesystem-specific acceleration
surface for trusted direct-local work such as copy, search and ZIP creation.
Executable availability is capability/platform dependent; do not depend on one
native tool being present merely because the OS family is known.

Generic Process Boundary
------------------------

``NativeCommandRunner`` is retained in Pathwise 4.1 for source compatibility and
for the library's internal filesystem-native acceleration. Direct application use
for generic process execution is deprecated. Application and Foundation process
work belongs to Runwire ``Command``, ``ProcessPolicy`` and ``ProcessRunner``.

Pathwise intentionally has no production dependency on Runwire. Ordinary file,
upload, download and archive operations remain portable to request-owned PHP,
shared hosting, CLI and serverless environments.

The legacy runner no longer keeps caller-derived executable lookups in a
process-global cache. This prevents persistent workers from retaining arbitrary
command names or stale PATH decisions across requests.

Safety Limits
-------------

Native execution is bounded by ``NativeExecutionLimits``. Defaults are finite:

* timeout: 300 seconds;
* stdout cap: 4 MiB;
* stderr cap: 4 MiB;
* termination grace: 1 second;
* polling interval: 10,000 microseconds.

Applications may provide stricter limits for their workload. Invalid limits are
rejected at configuration time. Runtime timeout, output-limit, startup, exit and
unsupported-capability failures are represented through the typed native
execution failure/exception surface.

The compatibility runner uses non-blocking pipe handling, bounded termination
and deterministic cleanup. Pathwise starts argument-vector commands; shell
fragments are not accepted as an execution API.

Storage Boundary
----------------

Native mode is direct-local-filesystem-only. ``StorageContext`` logical paths,
low-level mounted/default Flysystem paths and object-storage paths are rejected
for native execution even when a particular adapter happens to use a local
directory internally. Pathwise does not unwrap adapters to manufacture a native
filesystem guarantee.

In ``AUTO`` mode, an unavailable native capability is a reason to use the PHP
implementation. In forced ``NATIVE`` mode, it is an explicit typed failure.
Once a native command has started and fails, Pathwise does not silently mask the
failure by rerunning the operation through a different implementation.

Untrusted ZIP extraction never uses raw native ``unzip``. Hardened extraction
always passes through ``ZipEntryValidator`` and ``ZipArchiveExtractor`` so entry
paths, types, sizes, ratios and rollback behavior remain authoritative.

Example
-------

.. code-block:: php

   use Infocyph\Pathwise\Core\ExecutionStrategy;
   use Infocyph\Pathwise\DirectoryManager\DirectoryOperations;

   $ops = new DirectoryOperations('/tmp/source');
   $ops->setExecutionStrategy(ExecutionStrategy::AUTO)
       ->copy('/tmp/target');

Use native acceleration for large trusted local workloads only after measuring
it on the deployment platform. See :doc:`performance-portability` and
:doc:`storage-contracts` for the capability and release-workload guidance.
