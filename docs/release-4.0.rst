Pathwise 4.0
============

Pathwise 4.0 is the runtime-architecture and hardening major. It keeps Pathwise
framework-neutral while making ownership, storage topology, security policy,
resource bounds, and platform capabilities explicit.

Major Changes
-------------

Storage/runtime
~~~~~~~~~~~~~~~

* ``StorageContext`` is the recommended persistent-runtime storage registry and
  resolver.
* Named filesystems, defaults, lazy operators and custom drivers are isolated
  per context.
* ``StorageFactory`` is stateless; process-global custom-driver/mount gateways
  were removed from that layer/facade.
* Upload/download processors can consume ``StorageContext`` directly without
  global mount mutation.

Uploads/scanning
~~~~~~~~~~~~~~~~

* ``UploadSource`` provides typed framework-neutral mover/path/stream ingestion
  with explicit ownership and deterministic staging cleanup.
* ``MalwareScannerInterface`` / request / verdict / mode/status types replace
  ambiguous scanner callbacks.
* Required scanning fails closed; scanner backend text is not leaked as the
  stable upload error; scan input integrity is checked.
* Chunk sessions use strict IDs/manifests, explicit finalization and typed
  ``ChunkUploadState``.

Downloads
~~~~~~~~~

* ``DownloadPreparation`` and ``RangeDownloadMetadata`` separate metadata from
  delivery.
* ``streamChunks()`` owns range seek/discard/accounting and source cleanup for
  framework iterable bodies.
* Preparations are revalidated before streaming so stale/manually-created
  metadata cannot bypass current download policy.

Security/reliability
~~~~~~~~~~~~~~~~~~~~

* ``PolicyEngine`` is deny-by-default with explicit permissive opt-in.
* ZIP extraction is manifest-driven with traversal/collision/special-entry,
  size/ratio and write-time validation.
* Serialization validation does not instantiate untrusted objects.
* ``SafeSymlinkManager`` owns generic direct-local link safety.
* File transaction rollback/state failures are explicit.
* Native execution is argv-based, non-blocking and bounded by timeout/output/
  termination limits.

Queue/operations
~~~~~~~~~~~~~~~~

* ``FileJobQueue`` uses typed opaque leases, expiry/renewal ownership checks,
  stale-worker rejection, strict versioned state and crash-safe local
  persistence.
* Audit sinks include callback, local JSONL and partitioned options.
* Retention, checksum indexing/deduplication and file-watcher workflows return
  typed results and apply bounded/validated behavior.

Dependencies and Platforms
--------------------------

* PHP 8.4+
* PHP 8.4 and 8.5 are release-matrix targets.
* ``ext-fileinfo`` is required.
* ``league/flysystem ^3.35.2`` is required.
* ``psr/log ^3.0.2`` is a direct production dependency because public APIs
  type-hint PSR-3 interfaces.
* ZIP/POSIX/XML capabilities and remote Flysystem adapters remain optional.

Breaking Changes
----------------

The major intentionally removes/changes unsafe or ambiguous 3.x behavior. See
:doc:`migration-4.0` for storage topology, scanner, policy, queue, native,
archive, serialization, result-object, and local-capability migration steps.

Release Validation
------------------

The 4.0 release gate requires:

* complete unit/feature/regression suite;
* PHP 8.4/8.5 on supported CI platforms;
* stable and prefer-lowest dependency matrices;
* PHPStan/Psalm and PHPForge quality/security gates;
* Windows platform tests;
* optional adapter contract suite;
* clean-install validation;
* Sphinx documentation build with warnings treated as errors;
* release benchmark/stress workloads covering streaming, directories, archives,
  queue leases, observability and transaction paths;
* final security/capability review.

See :doc:`performance-portability` for workload interpretation and portability
limits.
