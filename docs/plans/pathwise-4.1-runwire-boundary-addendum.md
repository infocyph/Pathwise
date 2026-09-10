# Pathwise 4.1 — Runwire Runtime Boundary Addendum

## Status

Parent plan: `docs/plans/pathwise-4.1-upload-filesystem-trust-boundary-plan.md`

Runwire plan: `infocyph/Runwire` → `docs/plans/runwire-1.0-foundation-3-launch-plan.md`

This addendum replaces the parent plan's provisional references to a “future process-runtime library” with the concrete lower-level library **Runwire 1.0**.

It does **not** introduce a Pathwise → Runwire dependency. Pathwise remains independently usable as the filesystem/storage/upload trust-boundary library.

---

# 1. Final ownership boundary

## Pathwise owns

- storage contexts/mounts/adapters;
- canonical root containment;
- safe path resolution;
- symlink/reparse/path-race defenses that belong to filesystem operations;
- upload staging/materialization;
- server-generated safe destination naming;
- MIME/signature/content-policy integration;
- malware-scanner contract and Pathwise-native scanner pipeline;
- archive-entry/path/size/count/depth limits;
- data-only publication/storage semantics;
- filesystem permission/metadata helpers within Pathwise scope.

## Runwire owns

- process creation/execution;
- structured executable + argv execution;
- `proc_open` I/O/deadlines/output limits;
- `pcntl`/`posix` process supervision;
- UID/GID/session/process-group mechanics;
- worker supervision;
- OS-sandbox launcher integration;
- process runtime capabilities.

## Foundation/application owns

- deciding whether a stored artifact may be passed to an executable;
- authorizing the requested operation;
- selecting Runwire execution profile;
- mapping Pathwise artifact identity/path into trusted process arguments;
- choosing an external executable malware scanner if desired;
- deployment-level OS sandbox policy.

Hard invariant:

> Pathwise makes a path/artifact safe to handle as filesystem data; it does not make that artifact safe to execute as code.

---

# 2. No Runwire production dependency

Do not add:

```json
"infocyph/runwire": "..."
```

to Pathwise production requirements merely because Foundation uses both packages.

Reasons:

- path safety is meaningful without process execution;
- uploads/storage should remain usable in FPM/serverless/non-pcntl environments;
- most Pathwise consumers never execute external processes;
- coupling process capabilities into upload handling increases attack surface;
- Foundation is the correct composition layer.

Runwire may be used as a **development/reference integration** only if Pathwise adds a cross-library test fixture proving an executable scanner adapter contract. Even that is optional; avoid dependency churn unless the fixture catches real integration bugs.

---

# 3. External malware scanner composition

Pathwise already owns `MalwareScannerInterface` / scanner-mode semantics. Preserve that abstraction.

If Foundation wants ClamAV or another executable scanner through Runwire, the composition should live above Pathwise:

```text
Pathwise UploadPipeline
       ↓
MalwareScannerInterface
       ↑
Foundation/Application scanner adapter
       ↓
Runwire ProcessRunner
       ↓
trusted scanner executable
```

The adapter may:

- obtain the Pathwise-controlled staged file;
- invoke a registered/trusted executable through Runwire structured argv;
- apply a strict timeout;
- bound stdout/stderr;
- normalize exit result into Pathwise scanner outcome;
- avoid shell construction;
- clean temporary resources deterministically.

Do **not** move Runwire `ProcessRunner`, executable allowlists or process policy into Pathwise.

---

# 4. Scanner path trust

When an executable scanner is composed externally, distinguish two paths:

```text
scanner executable path  = trusted deployment configuration / Runwire policy
staged upload path        = Pathwise-controlled untrusted-data artifact
```

The upload path may be passed as a literal argv item after Pathwise has resolved it within the intended staging root.

Do not concatenate it into a shell command string.

Correct conceptual form:

```text
executable: /usr/bin/clamscan
argv: [--no-summary, /trusted-pathwise-staging/random-id]
```

not:

```text
"clamscan " + uploadedPath
```

Pathwise is responsible for the staged artifact's filesystem boundary. Runwire is responsible for process invocation semantics.

---

# 5. Uploads remain data-only by default

Strengthen the parent-plan invariant with the named runtime:

```text
upload
  ↓
Pathwise staging/validation/scan
  ↓
data-only storage/publication
  ↓
optional later Foundation-authorized operation
  ↓
Runwire
```

There must be no automatic transition:

```text
Pathwise upload -> Runwire execute
```

simply because a file extension, MIME type or user parameter suggests executable content.

Pathwise must never call `include`, `require`, `eval`, `exec`, `system`, `shell_exec`, `passthru`, `popen`, `proc_open` or `pcntl_exec` on an uploaded artifact as part of normal upload processing.

---

# 6. `noexec` / non-executable metadata remains defense-in-depth

Keep the parent plan's distinction:

- filesystem permission/non-executable policy reduces accidental direct execution;
- `noexec` mounts are useful deployment defense-in-depth;
- neither prevents an interpreter from reading a file and interpreting it if an application intentionally passes the path to PHP or another interpreter.

Therefore the primary invariant is architectural:

> Pathwise-managed untrusted upload/storage roots are data roots and are never implicitly selected as Runwire script entrypoints.

---

# 7. `ext-posix` scope remains narrow in Pathwise

Do not expand Pathwise's optional POSIX usage merely because Runwire exists.

Pathwise may use POSIX helpers only where they directly support filesystem ownership/metadata validation.

Process lifecycle/identity operations such as these belong in Runwire:

```text
posix_kill
posix_setsid
posix_setuid
posix_seteuid
posix_setgid
posix_setegid
posix_initgroups
```

Pathwise should not wrap them.

If Pathwise needs to compare UID/GID metadata for safe filesystem ownership checks, keep that code narrowly focused on the filesystem decision and do not grow a process privilege subsystem.

---

# 8. Archive extraction boundary

Runwire does not change Pathwise archive responsibilities.

Pathwise continues to own:

- entry traversal rejection;
- absolute path rejection;
- symlink/hardlink/special-entry policy where supported;
- aggregate uncompressed bytes;
- entry count;
- nesting/depth policy;
- destination containment;
- safe publication.

Do not delegate archive extraction to an arbitrary shell command merely because Runwire can safely execute processes.

If an external archive utility is ever used for a specialized format, it must be behind an explicit Pathwise/application adapter and must preserve Pathwise's authoritative destination/entry safety policy. It must not become a bypass around Pathwise extraction checks.

---

# 9. ReqShield + Foundation + Pathwise + Runwire chain

For a privileged operation using a user-selected stored artifact, use this ownership chain:

```text
user request
    ↓
ReqShield
validates operation ID, artifact ID and structured arguments
    ↓
Foundation
resolves authorization/capability
    ↓
Pathwise
resolves artifact under intended storage root/context
    ↓
Foundation
maps to registered Runwire operation
    ↓
Runwire
executes trusted executable + literal argv under bounded policy
```

No layer should collapse this into raw shell text.

---

# 10. Runwire server uploads

Foundation's native Runwire server does not change Pathwise ownership.

Expected web upload path:

```text
Runwire HTTP transport/body stream
        ↓
Webrick request/upload representation
        ↓
Foundation upload application policy
        ↓
Pathwise UploadSource / upload pipeline
```

Runwire owns network framing/body transport limits. Webrick owns HTTP request/upload representation. Pathwise owns storage/materialization/trust-boundary mechanics.

Avoid whole-body copies merely to adapt between Runwire/Webrick/Pathwise; preserve streaming/temp-file behavior where possible.

---

# 11. Effective Pathwise changes

The Runwire decision does **not** require a new Pathwise process API.

The effective Pathwise 4.1 work remains the work already defined in the parent plan:

- untrusted-data profile;
- canonical root containment;
- symlink/reparse/race hardening;
- private staging/publication;
- permission/non-executable semantics;
- archive hardening;
- process-security non-goals.

Add only these Runwire-specific clarifications/tests if useful:

- artifact paths containing shell metacharacters remain valid data paths when otherwise legal and are not modified merely for shell safety;
- scanner/application adapters must pass paths as literal argv rather than shell strings;
- Pathwise does not auto-execute uploaded data;
- a stored `.php`, `.sh` or similar filename remains data unless the application deliberately authorizes an external operation;
- no Runwire types are referenced from Pathwise public production API.

---

# 12. Tests

Add/retain tests proving:

- shell-looking filenames are handled as filesystem data according to Pathwise filename policy, not parsed as commands;
- executable-looking file contents do not trigger Pathwise PHP/shell interpretation;
- staged path cannot escape the scan root;
- symlink replacement between validation/scan/publication is rejected according to parent plan;
- archive extraction cannot escape even if an archive contains executable-looking names;
- Pathwise functions without Runwire installed;
- any reference external-scanner adapter, if added to tests, invokes a fake/structured runner contract without shell interpolation.

Do not add tests that assert a blacklist of strings such as `exec(` inside file contents.

---

# 13. Documentation wording to normalize

In final Pathwise 4.1 docs, replace generic wording such as:

```text
future process-runtime library
process-security library
ProcessGuard
```

with:

```text
Runwire
```

where referring to the concrete Infocyph process/runtime integration.

Still describe Runwire as an **external sibling/lower runtime** rather than a Pathwise dependency.

---

# 14. Completion gate addendum

Pathwise 4.1 runtime-boundary acceptance additionally requires:

- [ ] Pathwise has no production dependency on Runwire;
- [ ] Pathwise public API contains no generic process/shell execution surface;
- [ ] Runwire is named as the owner of process execution/supervision in integration docs;
- [ ] executable-scanner composition, if documented, uses an application adapter with structured Runwire argv;
- [ ] uploads/storage remain data-only absent explicit application authorization;
- [ ] Pathwise's `ext-posix` usage remains filesystem/metadata scoped;
- [ ] Runwire-native Foundation HTTP uploads still flow Webrick → Foundation → Pathwise rather than bypassing Pathwise;
- [ ] tests do not rely on dangerous-function/content blacklists.

All other parent-plan completion criteria remain unchanged.