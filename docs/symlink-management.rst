Safe Symlink Management
=======================

Namespace: ``Infocyph\Pathwise\FileManager``

``SafeSymlinkManager`` owns reusable local-filesystem mechanics for creating,
inspecting, and removing symbolic links inside explicit trust boundaries. It is
intended for application/framework adapters that own configuration and policy,
while Pathwise owns the filesystem safety rules.

Boundary Model
--------------

Construct the manager with two existing local directories:

* ``linkRoot`` — every managed symlink must remain below this directory.
* ``targetRoot`` — every managed target must remain inside this directory.

Relative link and target paths are resolved beneath their respective roots.
Absolute local paths are accepted only when they remain within the configured
boundary. Stream-wrapper/scheme paths are rejected because native symbolic links
are local-filesystem operations.

The manager rejects:

* parent-directory traversal,
* link paths outside ``linkRoot``,
* target paths outside ``targetRoot``,
* link parents that resolve through a symlink outside ``linkRoot``,
* target ancestors that resolve through a symlink outside ``targetRoot``,
* replacement of an existing regular file/directory,
* replacement of a symlink that points somewhere else, and
* removal of a symlink whose current target does not match the expected target.

Creation is no-clobber. Pathwise creates the final symlink directly instead of
creating a temporary link and renaming it into place. If another process creates
the destination concurrently, Pathwise re-checks the resulting path and only
treats the operation as idempotent when the new symlink already points to the
same expected target.

Creating Links
--------------

.. code-block:: php

   use Infocyph\Pathwise\FileManager\SafeSymlinkManager;

   $links = new SafeSymlinkManager(
       linkRoot: '/srv/app/public',
       targetRoot: '/srv/app/storage',
   );

   $created = $links->create(
       link: 'assets',
       target: 'public/assets',
       createTargetDirectory: true,
   );

``create()`` returns ``true`` when it creates the link and ``false`` when an
already-existing link matches the expected target. When
``createTargetDirectory`` is enabled, a missing directory target is created
beneath ``targetRoot`` only after its nearest existing ancestor has been
resolved and verified inside the configured target boundary.

Status and Removal
------------------

.. code-block:: php

   $status = $links->status('assets', 'public/assets');

   if ($status->matches) {
       $links->remove('assets', 'public/assets');
   }

``status()`` returns ``SymlinkStatus`` with:

* ``exists`` — a regular path or symbolic link occupies the link path,
* ``linked`` — the path itself is a symbolic link,
* ``matches`` — the symbolic link points to the expected target, and
* ``broken`` — the path is a symbolic link whose target no longer resolves.

A broken link created by this manager can still be identified and removed when
its stored absolute target exactly matches the expected in-root target path.
Pathwise does not use that fallback for arbitrary relative broken links.

Framework Ownership
-------------------

Pathwise deliberately does not read application configuration or decide which
links an application should expose. A framework/application layer should:

#. resolve its configured public/storage roots,
#. construct one ``SafeSymlinkManager`` for those roots,
#. map each configured link/target pair into ``create()``, ``status()``, or
   ``remove()`` calls, and
#. translate Pathwise exceptions/results into its own CLI or management output.
