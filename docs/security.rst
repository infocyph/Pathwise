Security Model
==============

Pathwise 4 treats filesystem input as a trust boundary. Security-sensitive
workflows prefer explicit rejection to silent fallback when a required
capability, ownership check, or validation step cannot be satisfied.

PolicyEngine
------------

``Infocyph\Pathwise\Security\PolicyEngine`` is deny-by-default:

.. code-block:: php

   use Infocyph\Pathwise\Security\PolicyEngine;

   $policy = (new PolicyEngine())
       ->allow('read', '/srv/public/*')
       ->allow('write', '/srv/work/*')
       ->deny('write', '/srv/work/protected/*');

Rules are evaluated in registration order and the last matching rule wins.
Rules may include a condition callback receiving operation, path and context.
``assertAllowed()`` throws ``PolicyViolationException`` when denied.

Use ``new PolicyEngine(defaultAllow: true)`` only when unmatched operations are
intentionally permissive.

Path and Root Safety
--------------------

Pathwise normalizes paths before sensitive operations and rejects null-byte and
traversal forms at logical/storage boundaries. ``StorageContext`` accepts only
relative logical paths or a configured ``name://`` scheme. Download allowed-root
checks resolve paths inside the same filesystem rather than trusting textual
prefixes across different storage operators.

Windows direct-local policy matching is case-insensitive; scheme/object paths
retain their storage semantics.

Uploads
-------

The upload pipeline layers:

* real HTTP provenance through ``is_uploaded_file()`` for ``processUpload()``;
* owned local staging for framework-neutral ``UploadSource``;
* actual-size enforcement rather than caller metadata trust;
* extension allow/block policy;
* MIME/profile checks;
* extension-to-MIME agreement and supported magic signatures;
* optional image-dimension limits;
* optional/required malware scanning;
* deterministic naming/collision validation;
* strict resumable-session IDs/manifests.

When malware scanning is ``REQUIRED``, a missing scanner or any non-clean
verdict rejects publication. Scanner exceptions map to a stable upload failure
while retaining the original exception. Pathwise verifies the scanner did not
mutate its private scan copy and removes scan state on every path.

Downloads
---------

``DownloadProcessor`` can enforce allowed roots, hidden-file blocking,
extension policy, maximum size, safe download names, and single-range parsing.
A ``DownloadPreparation`` is revalidated before the stream opens; callers cannot
use stale/manually-created preparation metadata as an authorization token.

The framework remains responsible for HTTP authorization/session logic and
conditional request policy.

ZIP and Archive Extraction
--------------------------

Archive processing is manifest-driven. Pathwise validates entries before
publication and rejects unsafe archive behavior including:

* absolute/traversal paths;
* normalized path collisions and duplicate output targets;
* unsupported/special entry types;
* source symlinks where the workflow requires regular-file input;
* configured entry-count, per-entry expanded-size, total expanded-size and
  compression-ratio violations;
* changes that invalidate assumptions during extraction/write.

Validation is repeated at the write boundary where necessary so an archive
cannot pass an early manifest check and then publish a different unsafe target.
Do not disable these controls for benchmark performance.

Safe Symbolic Links
-------------------

``FileManager\SafeSymlinkManager`` is a direct-local capability. Callers provide
allowed link and target roots explicitly. The manager validates containment,
existing link targets, replacement state and activation/removal rather than
blindly calling ``symlink()``/``unlink()``.

Application-specific public/storage path policy remains outside Pathwise; pass
the resolved roots to the manager.

Transactions and Atomicity
--------------------------

Local transactional mutation journals prior state and attempts rollback when a
transaction fails. Rollback failure is surfaced through
``TransactionRollbackException`` rather than hiding a potentially inconsistent
result. Invalid transaction lifecycle/state uses ``TransactionStateException``.

Remote/object storage must not be assumed to provide the same atomic rename,
locking, or rollback semantics as a direct local filesystem.

File Queue Integrity
--------------------

``FileJobQueue`` is direct-local and uses:

* a stable lock file;
* cryptographically random job/lease identifiers;
* typed lease ownership and stale-worker rejection;
* strict versioned JSON state validation;
* job/payload/total byte limits;
* same-directory temporary commit and replacement;
* orphan temporary cleanup under the exclusive lock.

Corrupt/empty/truncated/unsupported committed state fails explicitly. Pathwise
does not guess a replacement state after corruption.

Native Execution
----------------

Native commands use argv-style execution rather than shell command string
composition. ``NativeExecutionLimits`` bounds timeout, stdout, stderr,
termination grace and polling. Timeout/output-limit failures terminate and
clean up the child deterministically.

Native executables are optional capabilities. If an operation cannot safely
fall back to PHP, capability absence is surfaced rather than silently changing
semantics.

Serialization Boundary
----------------------

``SerializedValueValidator`` inspects serialized input defensively. It is not an
object-instantiation API and must not be used to reconstruct untrusted classes.
Keep application deserialization of trusted domain data outside Pathwise's
validation boundary.

Audit Data
----------

``AuditTrail`` and sinks are operational observability primitives, not a secret
store. Do not log credentials, tokens, full uploaded payloads, or backend error
material that should remain private. Prefer ``PartitionedAuditSink`` when one
local JSONL file could grow without bound.

Capability Failures
-------------------

Pathwise distinguishes invalid input, policy rejection, unsupported storage
operations, missing platform capabilities, and operational failures through
typed exceptions. Applications should branch on exception type/stable semantics
instead of parsing backend-specific message strings.

See :doc:`api-reference`, :doc:`upload-processing`, :doc:`download-processing`,
:doc:`symlink-management`, :doc:`queue`, and :doc:`performance-portability`.
