Trust Boundaries and Persistent Runtimes
========================================

Pathwise 4.1 treats filesystem artifacts as data. Validation, staging and
containment make an artifact safe to store or serve according to application
policy; they do **not** make arbitrary content safe to execute.

Ownership Model
---------------

The integration boundary is intentionally narrow:

* **Pathwise** owns filesystem/storage mechanics, canonical containment,
  private upload staging, controlled publication, hardened ZIP extraction,
  file metadata, download preparation and trusted direct-local filesystem
  acceleration.
* **Application policy** owns authorization, configured roots,
  public-name eligibility, scanner composition and decisions about later
  privileged use of an artifact.
* **The HTTP host** owns HTTP routing, conditional/range/cache semantics and response
  transport.
* **Runwire** owns generic process execution, deadlines/cancellation and process
  isolation primitives.
* the **OS/container** remains the final privilege, mount and execution-isolation
  boundary.

Pathwise has no framework dependency. Runwire is optional and is not a production dependency.

Borrowed Runwire 2.1.1 Context
------------------------------

Pathwise 4.2 accepts ``Integration\Runwire\RunwireExecutionContext`` constructed
from the host's active ``RuntimeContext``, optional ``RequestContext`` and
optional live ``CoroutineScope``. Forward the same object through intermediary
services; no implicit global runtime lookup is used. ``UploadProcessor``,
``DownloadProcessor``, ``FileCompression``, ``DirectoryOperations`` and
``SafeFileWriter`` expose ``withRunwire($execution, $operation)``.

.. code-block:: php

   use Infocyph\Pathwise\Integration\Runwire\RunwireExecutionContext;
   use Infocyph\Pathwise\StreamHandler\DownloadProcessor;

   // All three lifecycle values belong to the caller's active host.
   $execution = new RunwireExecutionContext($runtime, $request, $scope ?? null);
   $download = new DownloadProcessor();
   $download->withRunwire($execution, static function (DownloadProcessor $bound) use ($path, $send): void {
       $prepared = $bound->prepareDownload($path);
       foreach ($bound->streamChunks($prepared) as $chunk) {
           $send($chunk);
       }
   });

Consume lazy iterators inside the binding callback. Bind explicitly inside each
new Fiber/task: bindings belong to one owner and current Fiber, are restored in
``finally``, and do not implicitly inherit. Nested bindings must use the same
execution object. Completed requests, foreign runtime requests, wrong process
IDs, closed/foreign task scopes and stale owner runtime generations are rejected.

Cancellation and deadlines are borrowed. Checkpoints yield only when a live
supplied scope supports ``RUNWIRE_COROUTINES``. Lock retries and watcher intervals
use cooperative scope sleeps when available, otherwise synchronous waits;
borrowed waits check both request and scope cancellation/deadlines. Pass
``execution: $execution`` to ``FileWatcher::watch()`` or ``PathwiseFacade::watch()``.
``$execution->iterateChecksums()`` checks between completed hashes; a single hash
and individual filesystem/adapter/native subprocess calls remain synchronous.
Pathwise never closes/completes host requests/scopes or starts workers/event loops.

ZIP extraction checks bounded 64 KiB copies, revalidates destinations after the
last cooperative checkpoint, and checks before commit. Cancellation rolls back
earlier replacements and removes owned staging files. Cleanup always runs;
successful publications are not reported as cancelled after commit. When Runwire
is absent, use the ordinary APIs without constructing an execution object.

Transaction journals snapshot each canonical destination, including paths not
yet created. Rollback rechecks that identity before restoring or deleting a file;
a redirected symlink, parent path or relative working directory fails explicitly
with ``TransactionRollbackException`` rather than writing to the new destination.
ZIP staging/directory cleanup also rechecks destinations before deleting owned
names. It never follows a redirected parent into another directory.

Bindings isolate lifecycle context, not mutable operation settings. Use separate
upload/archive/directory/writer owners for overlapping operations. A shared
download processor should keep its configured policy immutable during streaming.

Untrusted Uploads
-----------------

For internet-facing data, opt into ``UploadTrustProfile::UNTRUSTED_DATA``.
The profile keeps one small policy surface rather than a broad execution DSL.
It guarantees finite default chunk count/size, server-generated naming, strict
content checks, private staging and restrictive local publication modes. Scanner
behavior remains explicit through ``MalwareScanMode``.

.. code-block:: php

   use Infocyph\Pathwise\StreamHandler\MalwareScanMode;
   use Infocyph\Pathwise\StreamHandler\UploadProcessor;
   use Infocyph\Pathwise\StreamHandler\UploadTrustProfile;

   $uploader = new UploadProcessor();
   $uploader->setTrustProfile(UploadTrustProfile::UNTRUSTED_DATA);
   $uploader->setDirectorySettings('/srv/app/private/uploads');
   $uploader->setExtensionPolicy(['pdf']);
   $uploader->setValidationProfile('document');
   $uploader->setMalwareScanner($scanner);
   $uploader->setMalwareScanMode(MalwareScanMode::REQUIRED);

An HTTP source keeps ``is_uploaded_file()`` provenance until Pathwise moves it
into an operation-owned staging location. Framework-neutral ``UploadSource`` and
trusted CLI/application ingestion also converge on private staging before the
artifact is validated and published. The original client filename is metadata,
not an authoritative destination path.

On local POSIX-style storage, strict staging uses restrictive directory/file
modes and strict publication never preserves attacker-controlled executable,
setuid or setgid bits. Remote/object storage does not pretend to support Unix
mode or inode guarantees that the adapter cannot provide.

External Scanner Composition
----------------------------

``MalwareScannerInterface`` stays framework-neutral. A process-based scanner is
composed by the application rather than by giving Pathwise an executable path:

.. code-block:: text

   Pathwise UploadProcessor
          -> MalwareScannerInterface
          <- application adapter
          -> Runwire Command + ProcessRunner
          -> trusted scanner executable

The executable is fixed/trusted application configuration. The Pathwise staging
path is literal argv data. ``REQUIRED`` scanning fails closed when no scanner is
configured, scanning fails, the staged artifact changes, or the verdict is not
clean.

Archive Boundary
----------------

Untrusted ZIP extraction always uses ``ZipEntryValidator`` plus
``ZipArchiveExtractor``. Raw native ``unzip`` is not a strict-extraction fast
path. Validation includes traversal/absolute/drive/UNC rejection, duplicate and
case-collision checks, symlink/special-entry rejection, entry-count limits,
per-entry and total expanded-byte limits, compression-ratio limits, entry-name
length, normalized path length and nesting depth.

Native ZIP creation and other filesystem-native acceleration may remain useful
for already-authorized trusted direct-local data. ``ExecutionStrategy::AUTO``
never authorizes a path; it only chooses an eligible implementation after the
application has established trust.

Trusted Public Files
--------------------

A runtime must not map a raw URL directly to disk. The application first
decides that a request targets a public asset and selects the configured public
root. ``PublicFileResolver`` then accepts only that root plus a relative
filesystem candidate:

.. code-block:: php

   use Infocyph\Pathwise\StreamHandler\PublicFileResolver;

   $asset = (new PublicFileResolver())->resolve(
       '/srv/app/public',
       'assets/app.css',
   );

   // $asset->path is now a canonically-contained local path suitable for
   // DownloadProcessor or an authorized response/file writer.

Traversal and root escape fail closed. Symlink behavior is explicit through
``PublicFileSymlinkPolicy``; the default rejects symlinks. Dotfile eligibility,
route policy and whether a name should be public remain application concerns.
Private upload, quarantine, temporary and chunk roots are not exposed unless an
application incorrectly chooses one of them as its trusted public root.

Generic Process Execution
-------------------------

Direct generic application use of ``NativeCommandRunner`` is deprecated in
4.1. The class remains source-compatible because Pathwise still uses bounded,
shell-free process execution behind trusted filesystem-native acceleration.
Applications may use Runwire 2.1.1 for generic process
work.

``NativeCommandRunner`` no longer retains arbitrary executable names in a
process-global capability cache. Pathwise must not grow supervisor, shell,
privilege-switching or runtime-cancellation APIs around it.

Persistent Worker Lifetime
--------------------------

Pathwise does not require a worker/runtime abstraction. Instead, mutable state is
kept at the correct lifetime:

* ``StorageContext`` is instance-scoped and may be long-lived for one immutable
  application storage topology;
* ``UploadProcessor`` is mutable policy state and should be transient/request
  scoped, or created from immutable application policy; do not share one mutable
  instance across unrelated concurrent requests;
* temporary upload/archive/scan resources are operation-owned and cleaned in
  ``finally`` paths;
* ``PathHelper`` does not retain request-derived normalized paths in static
  process state;
* ``FlysystemHelper`` global mounts/default filesystem are compatibility
  configuration state and must be bootstrapped deliberately and ``reset()`` in
  tests or runtimes that replace that configuration;
* immutable platform ownership resolution may remain process-lifetime state.

Symlink and TOCTOU Limits
-------------------------

Pathwise uses canonical roots, nearest-existing-parent resolution, link checks,
exclusive random temporary names, destination-side staging and rechecks near
publication. These materially reduce traversal/link/replacement races, but pure
PHP path checks are not a kernel sandbox against another hostile process that
can concurrently rewrite the same directory tree. Deployment ownership, mount
permissions, container/user namespaces and mandatory-access controls remain
defense in depth.

Local and Remote Guarantees
---------------------------

Local publication can use atomic rename when filesystem conditions permit; a
cross-device local publish copies to an exclusive destination-side temporary
file and renames only after verification. Remote adapters use temporary-object
and move semantics when provided, but Pathwise does not claim POSIX atomicity,
inode identity, symlink rules or Unix permissions for object storage.

See :doc:`upload-processing`, :doc:`malware-scanning`, :doc:`security`,
:doc:`native-execution`, :doc:`performance-portability` and
:doc:`storage-contracts` for the component-level contracts.
