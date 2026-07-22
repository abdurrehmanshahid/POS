# Security model

How access is decided, what was fixed, and what is deliberately still open.
Everything here is enforced **server-side**; UI hiding is never the only control.

---

## 1. The two portals

|  | Staff portal | Super admin panel |
| --- | --- | --- |
| URL | `/login` | `/superadmin` |
| Guard | `web` | `superadmin` |
| Table | `users` | `super_admins` |
| Access model | least-privilege, 16 permission keys | unconditional |
| Two-factor | required per-role (`roles.requires_2fa`) | **always** |
| Password reset | admin-issued temp password | none, recovery is a DB operation |

They are separate **tables and guards**, not two roles. The reason is concrete:
an institute Administrator holds `staff.manage`, which by design lets them
create users and edit roles. If the super admin were a row in `users`, every
staff screen would need a special case to hide and protect it, and one missed
`where` clause would expose the account that can delete everything. Isolation by
table means the staff screens simply cannot reach it, their queries run against
`users`, and the owner is not there.

Laravel keeps each session guard's identity under its own session key, which is
also what makes impersonation safe (§5).

---

## 2. Holes found in the prototype port, and what closed them

These are regression-tested in `tests/Feature/AuthSecurityTest.php` and
`tests/Feature/PrivilegeEscalationTest.php`. If one of those tests ever fails, a
real vulnerability has come back.

### 2.1 Account takeover via forgot-password, **critical**
The prototype's flow was: type a username → the page confirms the account exists
→ set a new password inline. Ported literally, anyone who could load the login
page and guess `adminansar`, a username printed on that very page, owned the
administrator account in about ten seconds. No email, no token, no proof of
identity.

**Fixed.** Nothing on that screen can change a password. Resets are either
admin-issued temporary passwords, or a tokenised expiring emailed link once
`INSTITUTE_SELF_SERVICE_RESET=true`. Both modes answer identically whether or
not an account matched, so it is no longer an enumeration oracle either.

### 2.2 Live credentials rendered into the login page
`adminansar · Bbt@Admin1` was printed in the HTML, and `fillAdmin()` /
`fillOfficer()` were server-callable Livewire actions.

**Fixed.** Both are gated on `app()->environment('local')`, in the template *and*
in the action, so hiding the buttons is not the only thing standing between an
attacker and an admin session.

### 2.3 Throttling defeated by rotating IPs
The limiter was keyed `identifier|ip`, which stops neither credential stuffing
from a botnet (each new IP starts a fresh counter) nor password spraying from
one host (each new account starts a fresh counter).

**Fixed.** `LoginThrottle` keeps two independent counters, 5 per account,
20 per IP, both decaying over 15 minutes, and trips on whichever fills first.
Decay rather than a permanent latch, because a permanent account lock hands any
passer-by a denial-of-service against a named officer.

### 2.4 Privilege escalation through `staff.manage`
Nothing stopped a user who could manage staff from ticking `scope.all` and
`revenue.view` onto their own role and reloading. `staff.manage` was silently
equivalent to every other permission.

**Fixed** by `PrivilegeGuard`, enforcing the standard delegation model:
- you cannot grant a permission you do not hold yourself;
- you cannot assign a role richer than your own;
- you cannot change your own role assignment;
- you cannot remove `staff.manage` from your own role;
- the last account able to administer the system cannot be removed or demoted.

A super admin is exempt from all of it, that is the point of a break-glass
identity, and it is why they exist to repair an institute that has locked itself
out.

### 2.5 Deactivation did not end a live session
`is_active` was only consulted on permission-gated routes, so revoking access
did nothing to a session already held. A dismissed employee kept working until
they chose to sign out, and a remember-me cookie let them back in days later.

**Fixed.** `EnsureActiveUser` runs on every authenticated request on both
guards, invalidates the session, and removal also clears `remember_token`.

### 2.6 A self-registration route, one line from being live
`routes/auth.php` defined `/register`, `/reset-password` and friends. It was not
loaded, but a single `require` would have exposed public self-registration on a
staff-only portal.

**Fixed.** The entire dead Breeze scaffold was deleted.

### 2.7 Nowhere to record dangerous actions
`audit_logs.challan_id` was a required FK, so the table could only ever describe
money. A user deletion or a password reset had no home.

**Fixed.** The table now records every consequential act, with a polymorphic
actor (staff / super admin / system) and subject. Two properties are load-bearing:

- **No foreign keys.** An append-only ledger must outlive the rows it describes.
  A real FK would either block the delete or cascade the evidence away with it, exactly wrong when the thing being recorded *is* the deletion.
- **Snapshotted names.** `actor_name` and `subject_label` are copies, so the
  trail still reads correctly after the account it names has been purged.

`AuditLog::booted()` throws on update and delete, so append-only is enforced in
code and not merely by convention.

### 2.8 Replay protection that silently did nothing, found by testing
The first implementation stored whatever `verifyKeyNewer()` returned. That
function returns boolean `true` rather than the matched timestep when the "old
timestamp" argument is null, so the first verification wrote `1` into the column
and every later code was compared against timestep 1 (i.e. 1970). Replay
protection was disabled after first use.

**Fixed** by passing `?? 0` so the library always returns the real integer
timestep, plus a defensive guard that refuses to persist a boolean. Covered by
`test_a_code_cannot_be_replayed`.

---

## 3. Two-factor (TOTP, RFC 6238)

Free, offline, no SMS and no vendor: the phone and the server independently
derive six digits from a shared secret and the clock.

- Secrets and recovery codes are **encrypted at rest** (`encrypted` cast,
  AES-256-GCM under `APP_KEY`). Recovery codes are additionally **hashed**, so
  even we cannot read them back, which is why they are shown exactly once.
- The QR is rendered locally as SVG by `bacon/bacon-qr-code`. It is deliberately
  never generated by an image API, because handing a third party a URL
  containing the TOTP secret would defeat the whole exercise. SVG output also
  needs no imagick/GD, which matters on shared hosting.
- **A password alone never creates a session.** When a second factor is
  enrolled, the verified identity is parked as a *pending* intent
  (`TwoFactorChallenge`) and `Auth::login()` is not called until a valid code
  arrives. Logging in first and redirecting to a code prompt would mean anything
  that skips the redirect is already past the second factor.
- Enrolment writes the secret only **after** the user proves their app generates
  a valid code, so a mis-scan cannot lock someone out of an account that now
  demands codes from an app that never received the secret.

## 4. Destructive actions

Two independent gates, because they stop different mistakes:

| Gate | Guards against |
| --- | --- |
| Type the record's own identifier (`R26-0009`, `aliraza`) | the wrong **target** |
| A fresh authenticator code | the wrong **person** at an unattended desk |

Neither subsumes the other. The typed phrase is the record's own ID rather than a
constant like `DELETE`, because a constant becomes muscle memory within a week.
Because `TwoFactor::verify()` burns the timestep it accepts, one code cannot
authorise two deletions, the second must wait for the authenticator to roll
over. Actors with no second factor (an officer) re-enter their password instead.

Removal is tiered:

- **Remove**, soft delete. Hidden everywhere, fully restorable, money trail
  intact. Restoring a staff account deliberately does **not** restore sign-in:
  recovering data and handing back access are different decisions.
- **Purge**, real `DELETE`, irreversible, and blocked outright when the record
  carries financial history (a student with paid challans, an account that
  signed admissions). The audit row, including a full snapshot of the record, is
  committed **before** the delete, inside the same transaction.

## 5. Impersonation

The super admin holds a `web` session for the target while their own
`superadmin` session persists underneath, so "stop viewing" is a guard switch
rather than a re-authentication, there is no window in which they are neither.

Attribution is never laundered: domain data legitimately records the officer (an
admission really is enrolled by them), while the audit row names the super admin
who was actually at the keyboard. Both facts are true and both are kept. A
permanent, non-dismissible banner is shown throughout.

## 6. Deliberately still open

- **`INSTITUTE_SELF_SERVICE_RESET` is off by default.** With no mail server, a
  reset form cannot prove address ownership, and one that cannot is an
  account-takeover form. Turn it on only once `MAIL_*` genuinely sends.
- **Officers are not forced into 2FA.** Front-desk staff on a shared counter
  start password-only; flip `requires_2fa` on the Officer role when every
  officer has the app.
- **`APP_DEBUG=true` locally.** Must be `false` in production, stack traces
  disclose paths, queries and config.
- **Recovery codes cannot be re-displayed.** By design; regenerate instead.
