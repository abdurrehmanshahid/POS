# Go-live checklist: Institute POS on Hostinger KVM 2

The executable form of `docs/DEPLOYMENT.md`, retargeted from Lightsail to a plain
Hostinger VPS. Every step has a stable ID. Tick the box, commit the file, and the
git history becomes the deployment log.

- Target: Hostinger **KVM 2**, plain **Ubuntu 24.04 LTS**, no control panel
- Host: **`pos.bigbinaryerp.com`** (decided 2026-08-29, `decisions.md` D53 —
  replaces `pos.bbt.edu.pk`)
- Runtime: PHP 8.4, MySQL 8.0.x, nginx, no containers
- Derived from: `docs/DEPLOYMENT.md`, `deploy/provision.sh`, `deploy/deploy.sh`,
  `.env.production.example`, `.github/workflows/ci.yml`,
  `app/Console/Commands/DeployPreflight.php`

## Progress

**Last updated 2026-08-29 · P1 done bar P1-10 (renewal). P2 rehearsal run 1
underway — `provision.sh` passed, P2-03 to P2-08 all verified.**
Network faults found and worked around en route: [SSH-02](#ssh-02--the-flaky-connection-diagnosed-and-worked-around).

**(earlier) P1 seven of ten done. DNS resolved and verified from
four vantage points — P2 is unblocked and is the next thing to do.**
[APEX-01](#apex-01--bigbinaryerpcom-is-half-down-right-now) — found and **fixed**
the same day.

**(earlier) P1 six of ten done. Key auth working, OS confirmed
24.04, [OS-01 closed](#os-01--the-box-came-up-on-2604) with no reinstall needed.
**DNS-01: the `pos` record must be created at PKNIC, not at Hostinger** — see
[DNS-01](#dns-01--the-zone-is-served-by-pknic-not-hostinger).

| Phase | Done | Notes |
| --- | --- | --- |
| **D** | 1 / 4 | D-03 settled and written up as `decisions.md` D51. D-01, D-02, D-04 still unanswered — none needs the server, so chase them while DNS propagates. |
| **S** | 1 / 8 | S-04 done (regenerated, no passphrase). **S-08 compromised and still live — see below.** |
| **P1** | 6 / 10 | P1-01→06 verified on the box. P1-07 with the client, P1-08 not started, **P1-09 now needs deliberate attention**. |
| **P2–P6** | 0 | Blocked on P1. |

**The two live risks, both from `decisions.md` D52** — the VPS is on the client's
Hostinger account, billed to them, with Admin granted to you:

0. **S-08 — the exposed root password is still accepted over SSH.** Confirmed on
   the box: `permitrootlogin yes`, `passwordauthentication yes`. No firewall yet
   (P3-05), no fail2ban yet (it arrives with `provision.sh`). Key auth is proved
   working and the browser console is proved working, so **the fix is not to
   rotate the password — it is to retire it now**, by bringing P3-02 forward.
   Rotating swaps one password for another and leaves the door in the wall.
1. **P1-10 — the term expires 2026-09-25**, on somebody else's card. That is 27
   days from today. A fee-collection system that goes dark on a lapsed renewal
   looks, from the counter, exactly like one that crashed.
2. **P1-09 — the OS-reinstall control is still unverified, and it now matters
   more, not less.** The browser console half is settled: P1-06 worked, and it
   was the recovery path during the key troubleshooting. But OS-01 resolving
   without a reinstall means the free test of the *reinstall* control never
   happened — and **P2-20, the second rehearsal run, is built entirely on it.**
   Verify it exists and is not greyed out **before** starting P2, not on the day
   you need it. If it is missing, the whole twice-through rehearsal needs
   rethinking, and that is a plan change worth knowing about early.

---

> **Where this file contradicts `docs/DEPLOYMENT.md` §13, this file is right and
> the reason is named at the step.** Four contradictions are known and recorded
> under [Doc bugs](#doc-bugs-found-while-writing-this). Two are fixed; two are
> not, and the corrected steps live here.

## How to use this

**Step IDs are stable.** `P3-08` means the same thing tomorrow as it does at 1am
on the night. When something fails, quote the ID — that is the whole point of the
numbering. See [When a step fails](#when-a-step-fails) for exactly what to send.

**Ticking:** `[ ]` not started · `[x]` done and verified · `[!]` failed, see notes
· `[-]` deliberately skipped, with a reason written beside it.

Each step carries **Verify** — the command whose output proves the step actually
happened. A step is not done because the command ran without error; it is done
when Verify says so. Steps marked **⚠ IRREVERSIBLE** cannot be undone after
go-live without data or identity damage.

## Status board

| Phase | Steps | What it is | Blocked by |
| --- | --- | --- | --- |
| **D** | 4 | Decisions the institute owes, in writing | nothing — start here |
| **S** | 8 | Off-box secret store, filled as you go | runs alongside P1–P4 |
| **P1** | 10 | Buy the server, DNS, first access | D-03 |
| **P2** | 20 | Rehearse twice on the same box | P1 |
| **P3** | 26 | Production bootstrap, strict order | P2 passing twice |
| **P4** | 12 | Backups off-box, then the pipeline | P3 |
| **P5** | 24 | Browser smoke test, client watching | P4 |
| **P6** | 5 | Day-7 retention verification | P5 + seven days |

Nothing takes real money until P5 passes. Nothing is trusted as a backup until
P6-02 prints `168`.

---

## D — Decisions the institute owes you

Answers **in writing** before the server is bought. None of these is engineering
work, and three of them cannot be undone after the first voucher prints.

- [ ] **D-01 ⚠ IRREVERSIBLE — `INSTITUTE_CODE_PREFIX`.** Currently `BBT-`.
      Stamped on every student code, admission number and challan number.
      Changing it later does not rewrite identifiers already printed on a
      document a parent is holding; the series just goes permanently
      inconsistent. **Verify:** the confirmed prefix is written down, and matches
      what goes into `.env` at P3-10.
- [ ] **D-02 — Payment-reversal rules.** The mechanism is built and tested
      (`decisions.md` D38, `docs/DEPLOYMENT.md` §15). Undecided: whether a
      **partial** reversal is permitted at all, and whether reversing means cash
      back today or credit against the next instalment. **Verify:** a written
      answer to both halves.
- [x] **D-03 — Server region. `Germany — Frankfurt`, 2026-08-29.** Latency is
      118 ms vs 145 ms against Lahore; §2.1 only starts worrying near 250 ms, so
      either works, and Germany is the better-supported region. Written up as
      `decisions.md` **D51**, which is what "in writing" means — a chat message
      is not a decision record.
      The jurisdiction half of the argument is deliberately not made: EU hosting
      for a Lahore institute's student records is a trade-off both ways, not an
      improvement, and if the institute has a view it is theirs to state.
- [ ] **D-04 — The P5 test payment.** P5 takes a real payment through the live
      system. Agree beforehand whether it is reversed, deleted, or kept as the
      first real record. **Verify:** written answer. Do not leave fictional money
      in a fee ledger by accident.

---

## S — The off-box secret store

A password manager, not this box, reachable by **at least two people**. Every row
is required to bring the institute back from a dump; losing any one makes the
others insufficient. This replaces `docs/DEPLOYMENT.md` §11 — the Lightsail row
becomes Hostinger.

- [ ] **S-01 ⚠ `APP_KEY`** — captured at P3-09, also at `/var/www/institute/.env`.
      Decrypts every stored TOTP secret. A dump without it is not a backup: a
      perfect restore locks out every enrolled account, `/superadmin` included,
      and there is no way back in through the application. **Never rotate after
      go-live.** Store it before running anything else.
- [ ] **S-02 Database password** — captured at P3-04, also at
      `/root/.institute-db-password`. Without it you cannot read or restore the
      database.
- [ ] **S-03 Super-admin credential** — captured at P3-15, printed exactly once.
      Without it there is no way into `/superadmin` to fix anything.
- [x] **S-04 SSH private key** — `~/.ssh/pos_bbt`, **regenerated 2026-08-29
      without a passphrase** after the first pair's passphrase stopped
      validating. Fingerprint `SHA256:DGRCjA5wUGZpyGR1KehDux1qdRQUe2Oa6n3EkyODZSE`.
      Passphrase-less is the right call for a single-purpose deploy credential —
      it is what S-04 assumed, and a key the pipeline cannot use unattended is not
      a deploy key. It does mean **the file alone is the credential**, so the two
      things that were already true matter more: get the private half into the
      password manager, and have full-disk encryption on the laptop.
      **Still to do:** confirm it is in the password manager, not only on disk.
- [ ] **S-05 rclone config** — created at P4-01, also at
      `~institute/.config/rclone/rclone.conf`. Losing it means losing access to
      your own backups.
- [ ] **S-06 Hostinger access** — **the account is the client's**
      (`abdurrehman545@gmail.com`); you hold a granted Admin role, not the login.
      See `decisions.md` **D52**. Store your own Hostinger credentials, and record
      *whose* account the subscription lives under and who at the institute can
      restore your access. Without it you cannot rebuild the VPS or reach the
      browser console — see P1-09.
- [ ] **S-07 Domain registrar login** — without it you cannot repoint DNS at a
      replacement.
- [x] **S-08 Root password (transient) — RETIRED 2026-08-29 via P3-02.**
      SSH now answers `Permission denied (publickey).` — `password` is gone from
      the offered methods, verified from outside. The exposed password can no
      longer be used to reach the box over SSH. **It still works on the hPanel
      browser console**, which is a different door, so rotate it there when
      convenient — but the urgent exposure is closed.
      **This recurs after every P2 reinstall.** A fresh image comes back with
      `PasswordAuthentication yes`; P3-02 is the first thing after every rebuild.
      *(original note below)*
- [!] **S-08 (detail) Root password (transient)** — issued at VPS setup.
      **⚠ WAS COMPROMISED: pasted into a chat transcript.**
      Confirmed on the box: `permitrootlogin yes`, `passwordauthentication yes`.
      No firewall yet (P3-05) and no fail2ban yet (it arrives with
      `provision.sh`), so this is a live credential on an internet-facing host.
      **Retire it rather than rotate it.** Key auth is proved (P1-04) and the
      console is proved (P1-06) — which are exactly the two preconditions P3-02
      asks for — so bring **P3-02 forward and run it now**. Rotating swaps one
      password for another and leaves the door in the wall; P3-02 removes the
      door. Rotate as well if you like, since the console still uses it.
      **This recurs.** Every P2 reinstall brings back a fresh image with
      `passwordauthentication yes`. P3-02 is not a one-off — it is the first thing
      after every rebuild. It is retired at **P3-02**, after which it is a
      fallback for the browser console only. Store it now anyway: between now and
      P3-02 it is the credential that gets you in if the SSH key does not work,
      and "we'll remember it" has never once been true. Delete the row after
      P3-02 rather than leaving a retired secret in the store.

---

## P1 — Buy the server and point DNS

~20 minutes of work, then wait for DNS.

- [x] **P1-01 Generate the key pair first, on your workstation.** Before anyone
      clicks Buy, so the public half is ready to paste into the purchase flow.
      The private half never leaves your machine.
      ```bash
      ssh-keygen -t ed25519 -C "pos-bbt-admin" -f ~/.ssh/pos_bbt
      cat ~/.ssh/pos_bbt.pub    # this is the half that is safe to send
      ```
      **Verify:** `~/.ssh/pos_bbt` and `~/.ssh/pos_bbt.pub` both exist; the
      private half is in the secret store (S-04).
      **Done 2026-08-29.** Key pair generated, public half pasted at P1-04.
- [x] **P1-02 hPanel → VPS → Set up VPS → KVM 2**, in the region decided at D-03.
      KVM 2 over-delivers on the documented target: §1 sizes for 4 GB / 80 GB,
      you are buying 8 GB / 100 GB.
      **Done 2026-08-29.** KVM 2, Germany — Frankfurt, $13.99/month, purchased on
      the client's account, term expiring **2026-09-25** (see P1-10).
- [x] **P1-03 ⚠ Operating system: plain Ubuntu 24.04 LTS, from the OS list.**
      **VERIFIED ON THE BOX 2026-08-29:** `VERSION_ID="24.04"`
      (`Ubuntu 24.04.4 LTS`), `amd64`, and **nothing listening on 80 or 443** —
      so the image shipped no web server and `provision.sh` has a clean field.
      The dashboard's "26.04" was a stale render; see
      [OS-01](#os-01--the-box-came-up-on-2604).
      Under the **Operating System** tab — not Application, not Panel, not an
      OS-with-something template. If the only Ubuntu 24.04 entry visible is
      bundled with a panel, stop and find the bare OS list.
      **Refuse:** Laravel · WordPress · cPanel · CyberPanel · Plesk · Webuzo ·
      anything ending in "+ Panel". Each ships its own web server, PHP and MySQL.
      `provision.sh` §1 and §6 install nginx and PHP-FPM as ordinary systemd
      services and write their own configs; they will collide over the same ports
      and paths, and the failure mode is a day of half-working state rather than
      a clean error.
      **Control panel: None.** **Verify:** after P3-01, `cat /etc/os-release`
      says 24.04, and `ss -lntp | grep -E ':80|:443'` returns **nothing** before
      provisioning — anything listening means an image shipped a web server.
      **Selected 2026-08-29:** plain OS → Ubuntu, no panel; Docker manager and
      malware scanner both declined, correctly — D2 rules out containers, and a
      scanner is one more unaccounted process on a box whose whole design is that
      you can name everything running on it.
      **The Verify above has not run yet** and is the one part of this step that
      actually proves anything. Do it at P3-01, before `provision.sh`.
- [x] **P1-04 SSH public key on the box.** **VERIFIED 2026-08-29** —
      `ssh -i ~/.ssh/pos_bbt root@168.231.104.82` connects with zero prompts.
      It took three attempts and the detours are worth keeping, because each one
      is a failure mode that will recur on every reinstall in P2:
      1. **The setup wizard's key never reached `/root/.ssh/authorized_keys`.**
         Proved by `ssh -v`: the connection established, the key was offered, the
         server rejected it. Not a dropped session — the key simply was not there.
         **Assume the wizard did not work and verify by logging in**, every time.
      2. **`authorized_keys` was garbled twice** by pasting multi-line commands
         into the slow browser console. One line at a time is the fix.
      3. **The first key pair's passphrase stopped validating**, so it was
         regenerated without one.
      **Host key:** `ssh-ed25519 SHA256:42pvUe0uUfELNJ0Qh/4WtJYytUoC/8fH5mMMTfUwuHE`.
      It changes on any reinstall; a change you did not cause is the one to notice.
      `ssh-keygen -R 168.231.104.82` after each rebuild.
- [x] **KEY-01 Remove the dead key from `authorized_keys`. DONE 2026-08-29.**
      `ssh-keygen -lf /root/.ssh/authorized_keys` now prints one line, the
      `DGRC…` key; the file was backed up to
      `/root/.ssh/authorized_keys.bak-20260829-121058` first, and the filter
      refused to write unless exactly one key survived. Key login re-verified on
      a fresh connection afterwards.
      *(original note below)*
- [x] **KEY-01 (detail) Remove the dead key from `authorized_keys`.** `root` currently
      authorises **two** keys, both commented `pos-bbt-admin`, which is exactly
      how the wrong one gets deleted:
      | Line | Fingerprint | Status |
      | --- | --- | --- |
      | 1 | `SHA256:jk+/86XCcvHooN3mqbAKqT0j7R+CVvZs0WtHariwmwA` | **dead** — the original pair, passphrase lost. Delete this one. |
      | 2 | `SHA256:DGRCjA5wUGZpyGR1KehDux1qdRQUe2Oa6n3EkyODZSE` | **live** — matches `~/.ssh/pos_bbt`. Keep. |
      Not urgent, but not merely clutter either: the dead key's private half still
      exists on the laptop in an unknown state, and it authorises `root` on a
      system that will hold fee records. Close it while the box is empty and the
      change costs nothing.
      **Verify:** `ssh-keygen -lf /root/.ssh/authorized_keys` prints one line, the
      `DGRC…` one, and a fresh login still works **before you close the session**.
- [x] **P1-05 Record what comes back.** IPv4 address, IPv6 if assigned, and the
      root password if a key was not used. Hostinger VPS IPs are static; there is
      no Lightsail-style "allocate a static IP" step here.
      **If a password was issued, treat it as burned the moment it was sent** —
      it is retired at P3-02.
      **Status:** a root password was issued and shown on screen; capture it as
      **S-08** and confirm it is stored before going further. The **IPv4 is still
      outstanding** — that is the next thing to grab once provisioning finishes,
      and P1-07 cannot start without it.
- [x] **P1-06 Find the browser console in hPanel, and open it once.**
      **DONE and genuinely used 2026-08-29** — it was the recovery path for the
      P1-04 key repair, which is the best possible proof that it works. The
      argument for opening it "while nothing is broken" paid for itself the same
      day. Do this
      now, while nothing is broken. It is your way back in if P3-05 locks you out
      of SSH. **Verify:** you reached a root shell through the browser, not
      through SSH.
- [x] **P1-07 Point DNS: `A` record `pos.bigbinaryerp.com` → the IPv4.**
      **DONE and VERIFIED 2026-08-29.** `A · pos · 168.231.104.82 · TTL 60`, and
      all four vantage points agree, so the P3-18 gate already passes:
      ```
      ns1.dns-parking.com (authority)  168.231.104.82
      1.1.1.1                          168.231.104.82
      8.8.8.8                          168.231.104.82
      local resolver                   168.231.104.82
      ```
      No Vercel address in any answer — the wildcard risk described below did not
      materialise, because `pos` now has an explicit record everywhere that
      matters. **P3-19 (certbot) is clear to run** whenever the box is ready.
      *(original guidance below)*
      **In progress 2026-08-29, and the ask needs correcting before the client
      acts on it — see [DNS-01](#dns-01--the-zone-is-served-by-pknic-not-hostinger).**
      The record must be added in the **PKNIC** panel. Adding it in Hostinger's
      DNS editor will never resolve, because Hostinger is not authoritative for
      this zone.
      A throwaway domain was considered and correctly rejected: `APP_URL`, the
      TLS certificate, the cached config and anything already printed all carry
      the hostname, so a placeholder buys a few hours now and costs rework across
      P3-10, P3-19 and P3-21. Wait for the real name.
      Do it early;
      propagation is the one thing on this list you cannot hurry, and certbot at
      P3-19 cannot run until it resolves.
- [ ] **P1-08 Repository URL and git credential. Answered 2026-08-29 — three
      questions, and the code settles the first one rather than preference.**

      **Which host? GitHub.** Settled by `decisions.md` **D54** — Azure DevOps
      was retired for lack of credits and the repository stays on GitHub. The
      reasoning that picked a single host still applies:
      [`deploy.sh:255`](../deploy/deploy.sh#L255) runs `git fetch --prune origin`
      and then `git cat-file -e "${RELEASE_SHA}^{commit}"`, refusing if the
      commit is absent. `RELEASE_SHA` is `github.sha` — the commit CI built and
      a human approved at the P4-06 Environment gate. The box's `origin` must be
      the same host that produced that SHA; pointing it at a mirror is a race
      that stays invisible until the day the two have drifted.

      **Private? Yes** — GitHub's API answers `404` unauthenticated.

      **Deploy key exists? No — generate one, on the box.** Generating it there
      rather than copying one in means the private half never travels.
      Read-only, never a developer PAT.
      ```bash
      sudo -u institute ssh-keygen -t ed25519 -N "" -f ~institute/.ssh/id_ed25519
      sudo -u institute cat ~institute/.ssh/id_ed25519.pub
      # add at: Settings > Deploy keys > Add deploy key
      #         leave "Allow write access" UNCHECKED
      ```
- [ ] **P1-08a ⚠ The bootstrap clone needs a credential too — resolved into two
      different answers, because the two directories have different needs.**
      A sequencing gap in `DEPLOYMENT.md` §13, same family as DOC-03: **P3-03
      clones the repo to `/tmp/pos` as root** — before the `institute` account
      exists, so before the §8.5 deploy key can be generated. On a private
      repository that clone fails at the first step of the bootstrap.

      **`/tmp/pos` — no credential needed at all.** `provision.sh` uses **no git**
      (verified: no `git` invocation anywhere in it). It just needs the files.
      Ship the tracked tree over the SSH access you already have:
      ```bash
      git archive --format=tar HEAD | \
        ssh -i ~/.ssh/pos_bbt root@168.231.104.82 'mkdir -p /tmp/pos && tar -x -C /tmp/pos'
      ```
      **Prefer this to `rsync` of the working directory.** `git archive` sends
      exactly what git tracks, so `.git`, `node_modules` and `vendor` are excluded
      by definition rather than by remembering three `--exclude` flags — and, more
      importantly, so is anything **untracked**. The repository root currently
      holds two untracked spreadsheets:
      ```
      student_details_report (45).xlsx      55 KB
      student_details_report-121.xlsx        7 KB
      ```
      Those are real student records. A plain `rsync -avz <repo>/ root@…` uploads
      them to a throwaway rehearsal box that will be reinstalled and forgotten,
      and **P2 says explicitly: no production data.** The exclude list would have
      to grow a line for every such file anyone ever drops in the tree, which is
      a rule that fails silently the first time somebody forgets.

      **`/var/www/institute` — a real clone, and it is needed for P2, not just
      P3.** `deploy.sh` is a git program: `git rev-parse HEAD` (line 107),
      `git fetch --prune origin` (255), `git cat-file -e` (257),
      `git reset --hard "$RELEASE_SHA"` (374). A copied directory is not a
      repository and every one of those fails.
      So the read-only deploy key is **not** deferrable to the production
      bootstrap: P2-12 through P2-16 exercise `deploy.sh`'s refusals, and they
      cannot run without it. Generate it during the rehearsal, on the box, as the
      `institute` account (§8.5) — it is the same five minutes either way, and
      doing it in P2 is what proves the P3 step works.

---

## OS-01 — the box came up on 26.04

> **CLOSED 2026-08-29 — a false alarm from the dashboard, and no reinstall was
> needed.** Confirmed on the box itself:
> ```
> PRETTY_NAME="Ubuntu 24.04.4 LTS"
> VERSION_ID="24.04"
> amd64
> (nothing on 80/443)
> ```
> The dashboard's "Ubuntu 26.04 LTS" was a stale render. The OpenSSH build string
> called it correctly before we could log in. **The rule that saved the box here
> is worth keeping: confirm on the machine, never on the panel** — the panel
> reports what it was asked to install.
>
> The analysis below is left in place deliberately. If a future rebuild lands on
> a release `provision.sh` was not written for, this is the reasoning, and
> [`provision.sh:70`](../deploy/provision.sh#L70) being a `warn` rather than a
> `die` is still true and still worth fixing.

**Found 2026-08-29, before `provision.sh` ran. Blocked P1-03.**

### First: confirm it on the box, not on the dashboard

A hosting dashboard reports the **template it was asked to install**, which is
not always what booted. Thirty seconds settles it, and you need the web console
open for P1-06 anyway:

```bash
cat /etc/os-release              # VERSION_ID is the authority, not the panel
ss -lntp | grep -E ':80|:443'    # must return NOTHING — this is the P1-03 verify
```

Do not wipe a box on the strength of a label. If `VERSION_ID="24.04"` this item
closes and you have also completed the half of P1-03 that was still outstanding.

### Update 2026-08-29: the evidence now points at 24.04, not 26.04

The SSH handshake reports the server's own build string:

```
remote software version OpenSSH_9.6p1 Ubuntu-3ubuntu13.18
```

**`OpenSSH_9.6p1` with an `ubuntu13.x` revision is Ubuntu 24.04 (noble).** 25.10
and 26.04 ship the OpenSSH 10.x series. A box actually running 26.04 would not
answer with this string.

So the dashboard label looks wrong, which is exactly the case this section was
written to guard against — **do not wipe a box on the strength of a label.**
Treat this as strong evidence, not proof: `cat /etc/os-release` on the box is
still the authority, and it is the first thing to run once key auth works
(P1-04). If `VERSION_ID="24.04"`, this whole item closes, no reinstall is needed,
and P1-03 ticks properly.

Everything below stands as the analysis **if** the box does turn out to be 26.04.

### If it really is 26.04 — what actually breaks

The severity is not evenly spread, and the most dangerous part is the part
easiest to miss.

**1. The guard does not stop you. This is the real problem.**
[`provision.sh:70`](../deploy/provision.sh#L70) reads:

```bash
grep -q "24.04" /etc/os-release || warn "Not Ubuntu 24.04. ..."
```

`warn`, **not `die`**. So `provision.sh` proceeds, and the only notice is one
yellow line that scrolls past inside a multi-minute apt install. The failure mode
is not "it stops and tells you" — it is a box that provisions apparently fine and
diverges from the documented one in ways nobody looks for until something else
goes wrong. Every other refusal in this system is a `die`. This one is not, and
that is worth fixing regardless of how the OS question lands.

**2. MySQL — a real divergence, but not the catastrophe it looks like.**
[`provision.sh:156`](../deploy/provision.sh#L156) installs `mysql-server`
unpinned. On 24.04 that is the **8.0.x** line; on 26.04 it will almost certainly
be **8.4**, since 8.0 reached end of life in April 2026.

That contradicts P3-04 and §7.3, which say to record 8.0.x and gate CI on it. But
the application is **not** untested there: `.github/workflows/ci.yml` already
runs a **`mysql:8.4` leg**, and the `CI` gate job `needs: [tests, assets]`, which
rolls up *every* matrix leg — a failure on the 8.4 leg fails the required check exactly like the
8.0 one. That leg has already earned its place once, catching an
`admissions.status => 'active'` value absent from the column's enum. So this is a
**documentation invariant to correct, not a correctness risk**: if you land on
8.4, record 8.4 at P3-04 and note that the 8.4 leg is now the one mirroring
production.

**3. PHP — the genuine unknown.** 24.04 ships PHP 8.3, which is the entire reason
[`provision.sh:84`](../deploy/provision.sh#L84) adds `ppa:ondrej/php`. 26.04 may
well ship 8.4 natively, making the PPA unnecessary — but the script adds it
unconditionally, and `apt-get update -qq` against a PPA with no packages for that
release is exactly what `-qq` swallows. Unproven either way, and unproven is the
problem.

**4. Everything else** — nginx, Node 24 via NodeSource, certbot, fail2ban, the
systemd units, `ufw` — is release-neutral and fine.

### Recommendation: reinstall to 24.04 — and the reason is not "26.04 breaks"

24.04 is supported to 2029, so there is no lifecycle pressure to be on 26.04, and
the cost of changing now — an empty box, a few minutes — is the lowest it will
ever be.

The argument is §12. **Nothing in this system has ever provisioned a real
machine.** That is already one large unknown, and the entire P2 rehearsal exists
to retire it. Running an unrehearsed path on an OS the script itself says it was
not written for stacks a second unknown on the first, and when something fails
you will not know which one you are looking at. One unknown at a time is the
whole discipline here.

### If 24.04 is not offered

Then it is a decision, not an error, and there are two honest options:

- **Ask Hostinger support** whether 24.04 is available on KVM 2 in Frankfurt. It
  is a current LTS, so its absence from the picker is more likely a catalog gap
  than a policy.
- **Proceed on 26.04 deliberately.** This is defensible, because P2 is precisely
  the machinery that turns it from a gamble into a known quantity — two clean
  rehearsal runs on 26.04 prove the path on 26.04. Taking this route, three
  things must change rather than be assumed:
  1. Turn [`provision.sh:70`](../deploy/provision.sh#L70) from `warn` into a
     refusal that names the release it found and requires an explicit
     `ALLOW_UNTESTED_RELEASE=1` to continue. A silent divergence is the failure
     mode; an acknowledged one is fine.
  2. Record the **actual** MySQL version at P3-04 and correct §7.3 and the CI
     note, rather than leaving two documents asserting 8.0.x.
  3. Watch the ondrej PPA step specifically on the first rehearsal run, and drop
     it if 26.04 ships PHP 8.4 in main.

  Write the outcome up in `decisions.md` as **D53** either way — this is exactly
  the kind of call a later engineer needs to disagree with on purpose.

### Order of operations

1. Open the web console — **P1-06**, wanted regardless.
2. `cat /etc/os-release` and the `ss` check — confirms or clears this item, and
   closes the outstanding half of P1-03.
3. If 26.04: **OS & Panel → reinstall**, and see whether 24.04 is offered. This
   doubles as the live test for **P1-09**.
4. A reinstall changes the host key — clear the stale entry before your next
   login: `ssh-keygen -R 168.231.104.82`
5. Re-run step 2 on the fresh box, then tick P1-03 properly.

Beyond step 2, do not configure anything until this is settled. There is no point
setting up a box you are about to wipe.

---

## DNS-01 — the zone is served by PKNIC, not Hostinger

> **SUPERSEDED 2026-08-29 by [DNS-02](#dns-02--the-domain-hunt-and-where-the-record-actually-goes)
> and `decisions.md` D53.** The hostname is now `pos.bigbinaryerp.com`, so PKNIC
> is no longer in the path. Kept in full, because the finding was right, it cost
> real time to establish, and `bbt.edu.pk` remains the institute's **email**
> domain — so this is still the map if anything ever needs a record there.
>
> The lesson generalised, and it caught us twice in one day: **the panel you are
> registered with is not necessarily the panel that answers.**

**Established 2026-08-29 by querying PKNIC's own authoritative nameserver, so
none of this is inference from a cache.**

```
$ dig @root-c1.pknic.pk A bbt.edu.pk
bbt.edu.pk.     86400  IN  A    217.21.91.125          <- PKNIC answers directly

$ dig @root-c1.pknic.pk A pos.bbt.edu.pk
pk.             14400  IN  SOA  pknic.pk. ...          <- does not exist

$ dig @root-c1.pknic.pk NS bbt.edu.pk
pk.             14400  IN  SOA  pknic.pk. ...          <- no delegation at all
```

### Three things follow, and two of them change what you ask the client

**1. The record goes in the PKNIC panel.** `bbt.edu.pk` has **no NS records**
delegating it anywhere — PKNIC serves the zone flat and answers the apex `A`
itself. So whoever holds the domain's PKNIC login is the only person who can
create `pos.bbt.edu.pk`. If the client adds it in Hostinger's DNS zone editor it
will do precisely nothing, and the failure is silent: you would sit watching
`dig` return nothing, with no error anywhere to explain it.

The apex pointing at `217.21.91.125` — a Hostinger address — is what makes this
easy to get wrong. The institute's website is on Hostinger; its **DNS is not**.
That split is common and it is exactly why "Hostinger manages the DNS" was the
wrong assumption, and why guessing at `ns1.dns-parking.com` would have sent you
querying a server that has never heard of this zone.

**2. It is not propagating — it does not exist.** The authority itself returns
NXDOMAIN. There is nothing in flight to wait for. Until someone creates it at
PKNIC, no amount of patience changes the answer.

**3. Negative caching here is four hours, not minutes.** The `pk.` SOA minimum is
**14400** seconds, which is the negative-cache TTL. So retrying plain
`dig pos.bbt.edu.pk` every five minutes can keep showing nothing for **up to four
hours after the record is live** — the public resolver is serving you a cached
"does not exist", not a fresh answer.

Check the authority instead, which cannot be stale:

```bash
dig @root-c1.pknic.pk +short A pos.bbt.edu.pk    # the truth, immediately
dig +short A pos.bbt.edu.pk                      # what the world sees, lags by up to 4h
```

**Both matter, and for different reasons.** The first tells you the client has
done their part. The second is what **certbot uses at P3-19** — Let's Encrypt
resolves publicly, so it cannot issue until the second one answers. Plan for the
gap rather than being surprised by it: P3-18 is the gate, and it is the public
view that has to pass.

### Ask the client for

- The record created **at PKNIC**: `A` · host `pos` · value **`168.231.104.82`**
- **TTL 300** if PKNIC lets them set it. The existing apex record is at 86400 —
  a full day — and inheriting that means any mistake takes a day to correct.
  Raise it after go-live once the address has stopped moving.
- Confirmation of **who holds the PKNIC login** (→ **S-07**). It is not Hostinger,
  and if it turns out nobody at the institute knows, that is better discovered
  now than on the night TLS has to be reissued.

---

## DNS-02 — where the record goes, and the migration caveat

**Settled 2026-08-29. The host is `pos.bigbinaryerp.com` — `decisions.md` D53.**
Supersedes DNS-01.

### Add the record here

**hPanel → Domains → `bigbinaryerp.com` → DNS / Nameservers → DNS records.**

| Field | Value |
| --- | --- |
| Type | `A` |
| Name | **`pos`** |
| Value | `168.231.104.82` |
| TTL | **60**, or the lowest offered |

Three things that were wrong on the first attempt at this form, all of which
save cleanly and fail later:

- **The domain selector** — top-left, easy to leave on the wrong zone.
- **`pod` instead of `pos`.** It resolves fine and breaks nothing until certbot
  fails at P3-19 against a name that does not exist, with nothing visibly wrong.
- **TTL `14400`** is the prefill — four hours, which is how long a typo lives.
  Raise it after go-live once the address has stopped moving.

### ⚠ The zone is mid-migration — do not run certbot until it settles

`bigbinaryerp.com`'s nameservers moved from Vercel to Hostinger on 2026-08-29,
**while it was serving a live site.** Until every resolver has dropped the old
delegation, two authorities answer for this zone and they disagree:

```
old (Vercel)     *  wildcard  ->  216.198.79.x     <- answers for `pos` too, wrongly
new (Hostinger)  pos  A       ->  168.231.104.82
```

**That wildcard is the trap.** A resolver still on Vercel does not fail for
`pos.bigbinaryerp.com` — it returns a confident wrong answer. So an
HTTP-01 challenge can be handed to the wrong host, and a certbot failure here
burns one of five duplicate certificates per week.

**P3-18 is the gate, and it must pass from more than one place:**

```bash
dig @ns1.dns-parking.com +short A pos.bigbinaryerp.com   # authority: instant truth
dig @1.1.1.1 +short A pos.bigbinaryerp.com               # public resolver
dig @8.8.8.8 +short A pos.bigbinaryerp.com               # a second one
```

All three must return **`168.231.104.82`** and nothing else. Any Vercel address
in any answer means the migration has not finished — wait, do not proceed to
P3-19.

### Also not ours, but do not let it go dark

`www.bigbinaryerp.com` was serving a live site on Vercel and still answers 200.
Once the old delegation expires it resolves purely from the Hostinger zone, whose
only `www` entry is `CNAME -> bigbinaryerp.com`. **Confirm that zone reproduces
what Vercel was serving**, or the marketing site goes down hours after the
change, looking causeless. Nothing about the POS depends on this — but the same
zone carries both, and a dark company site on go-live week is its own problem.

### The two dead ends, recorded so they are not re-tried

**`pos.bbt.edu.pk`** — PKNIC serves the zone flat, no delegation. Full finding in
[DNS-01](#dns-01--the-zone-is-served-by-pknic-not-hostinger); still the map if
the institute's **email** domain ever needs a record.

**`pos.bigbinarytech.com`** — briefly chosen while `bigbinaryerp.com` was
unreachable, and correct on the facts at that moment: `ns1/ns2.dns-parking.com`,
verified `NXDOMAIN`, no wildcard on the zone. Kept here as the fallback if the
nameserver migration above has to be reversed.

### The rule that would have saved the session

**Run `dig NS <domain>` before typing into any DNS form.** Three domains, three
different authorities, and in every case the panel we were registered with was
not the panel that answered.

---

## APEX-01 — bigbinaryerp.com is half down right now

> **RESOLVED 2026-08-29, same day, about ten minutes after it was found.** The
> `2.57.91.91` record was deleted. Verified at the authority:
> ```
> apex  216.198.79.1        <- one record, the Vercel one
> www   CNAME -> apex
> ```
> Live: apex `307` -> www, www `200`. Back to 100%, and `pos` untouched at
> `168.231.104.82`.
>
> **Worth keeping as a pattern.** The symptom would have been "the website is
> flaky" — half of any given person's refreshes working — which is close to
> unactionable as a bug report, and it appeared hours after the change that
> caused it. It was only visible because P1-07 verification asked the authority
> what the zone *actually* contained rather than trusting one lookup. **Two `A`
> records for one name is a coin flip, not a fallback:** DNS round-robins, it
> does not health-check.

**Not the POS. Found 2026-08-29 while verifying P1-07, on the same zone.**

The apex carries **two `A` records**, and one of them is dead:

```
$ dig @ns1.dns-parking.com +short A bigbinaryerp.com
216.198.79.1        <- Vercel
2.57.91.91          <- Hostinger
```

DNS round-robins between them, so each visitor gets one or the other. Tested
directly against each:

```
via 216.198.79.1    http=307 -> https://www.bigbinaryerp.com/   server: Vercel
via 2.57.91.91      http=000                                    <- connection failed
```

`www` is the same, because it `CNAME`s to the apex:

```
via 216.198.79.1    http=200   server: Vercel
via 2.57.91.91      http=000
```

**So roughly half of all visitors to the company website are getting a connection
failure, right now.** `http=000` is not a 500 or a 404 — nothing accepted the
connection at all.

**This is not transitional and will not heal on its own.** It is two records in
the zone, not stale caching. It arrived with the nameserver move: the Hostinger
zone was populated with a Hostinger address alongside the Vercel one that was
actually serving the site.

**Fix:** delete the `2.57.91.91` `A` record from the apex — and from `www` if it
has its own — leaving only the Vercel address. The site returns to 100%
immediately at TTL 300.

**It does not touch the POS.** `pos` has a single explicit `A` record and
resolves correctly from every vantage point. Flagged here only because it is the
same zone, it is live, and a company site that fails half the time during
go-live week is its own problem — one that looks intermittent and unreproducible
to whoever reports it, since half their refreshes work.

---

## P2 — Rehearse twice, on the same box

`docs/DEPLOYMENT.md` §12 is explicit: **production must not be the first machine
this path has ever run on.** None of these scripts has provisioned a real
instance. Hostinger bills monthly rather than hourly, so a second VPS is a poor
fit — **reinstalling the OS from hPanel** gives you the same clean slate for
free, at exactly the production spec.

Use throwaway secrets and no production data. Budget half a day.

**Run 1 — the whole of P3 except certbot and the real `.env` values:**

- [x] **P2-01** `provision.sh` runs from bare, one command, no prompts —
      **PASSED 2026-08-29**, run detached, exit clean, full summary printed.
- [x] **P2-02** `provision.sh` a **second** time — **PASSED 2026-08-29.** No
      errors, and the step-by-step diff is exactly the shape idempotency should
      have: the second run *skipped* the install steps and *reused* state rather
      than repeating or recreating it.
      ```
      < Installing Composer                    <- run 1 only
      < Installing Node 24                     <- run 1 only
      < Pinning MySQL to loopback              <- run 1 only
      < Creating a 2G swap file                <- run 1 only
      > Reusing the existing database password from /root/.institute-db-password
      ```
      **That password line is the one that matters.** A second run that minted a
      fresh password would have silently orphaned the `.env` of a live site — the
      repair tool breaking the thing it was run to repair. It reuses instead.
      The only other difference between the two runs is the clock timestamp.

      **Two warnings it emits are known and expected here**, both from Lightsail
      assumptions this deployment no longer matches:
      `iptables-persistent is not installed, so these rules are not saved across
      reboots` and `The REAL firewall is the Lightsail console`. On Hostinger
      there is no such console — which is precisely why **P3-05 (`ufw`)** exists
      and is not optional. Until it runs, this box has no firewall at all.
- [x] **P2-03** MySQL version recorded and it **is** the 8.0.x line:
      `mysql Ver 8.0.46-0ubuntu0.24.04.3`. Record this in `DEPLOYMENT.md` §7.3
      after the production run; it is the version the gating CI leg must match.
- [x] **P2-04** FPM pool verified: `pm = ondemand`, `pm.max_children = 6`,
      `memory_limit = 256M`, socket `/run/php/php8.4-institute.sock`.
- [x] **P2-05** `/var/backups/institute`, `/var/www/institute-builds` and
      `/var/www/institute` all exist, `institute:institute`, mode `750`.
- [x] **P2-06** `/etc/tmpfiles.d/institute.conf` present and both lock files
      exist — `/run/institute-deploy.lock`, `/run/institute-backup.lock`.
- [x] **P2-07** `System clock synchronized: yes`, timezone UTC. `timedatectl` reports synchronised — and the script **fails
      loudly** if it does not. TOTP codes are computed from the clock; drift
      locks out every enrolled account at once, including the only `/superadmin`
      account, which is the one you would use to fix it.
- [x] **P2-08** `ss -lntp` shows `127.0.0.1:3306` and nothing else — the money
      database is not reachable from outside the box.
- [ ] **P2-09** Migrations run; **only** `RolePermissionSeeder` and
      `SuperAdminSeeder`, by name
- [ ] **P2-10** `/ready` returns 200; returns **503** during `artisan down`
- [ ] **P2-11** Queue worker and scheduler timer are both running **after a
      reboot** — `systemctl reboot`, wait, then
      `systemctl is-active institute-queue institute-scheduler.timer`

**Prove the refusals actually refuse.** These are the guardrails that matter on a
bad night, and an untested guardrail is a comment:

- [ ] **P2-12** Bad `RELEASE_SHA` refuses — **before the backup**, before
      maintenance mode
- [ ] **P2-13** Missing build artifact refuses — same point
- [ ] **P2-14** `TRUSTED_PROXIES=*` refuses
- [ ] **P2-15** `BACKUP_KEEP=14` refuses
- [ ] **P2-16** Two deploys at once — the second exits immediately on the `flock`
- [ ] **P2-17** `backup:run` writes both a dump and a `.json` manifest
- [ ] **P2-18** `backup:verify` restores into the scratch database and passes
- [ ] **P2-19** Restore into a scratch database, then **complete a `/superadmin`
      TOTP login**. Row counts matching is not proof of recovery; this is.

**Then:**

- [ ] **P2-20 Reinstall the OS from hPanel and do the whole of run 1 again**,
      with no hand-holding. If it needs an undocumented fix, the fix belongs in
      the script or in this file — not in your head. Then reinstall and prove it
      a third time. A step that lives only in somebody's memory is a step that
      will not happen at 2am.

> **The one thing you can only rehearse once:** certbot against the real
> hostname. Let's Encrypt allows five duplicate certificates per week, so a
> couple of runs is fine — do not loop on it. Everything else here is unlimited.

---

## P3 — Production bootstrap

**The numbering is load-bearing.** Two orderings in particular cannot be
swapped: the secure-cookie flag straddles certbot (P3-10 → P3-19 → P3-21), and
every backup happens before its migration.

- [ ] **P3-01 SSH in as root.** Hostinger's Ubuntu image gives you root, not the
      `ubuntu` account the Lightsail runbook assumes. That is the only
      difference; nothing in `provision.sh` depends on the account name.
      ```bash
      ssh -i ~/.ssh/pos_bbt root@<vps-ip>
      ```
- [ ] **P3-02 Lock down SSH before anything else is running.**
      ```bash
      passwd -l root      # only if a password was issued at P1-05
      sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config
      sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin prohibit-password/' /etc/ssh/sshd_config
      systemctl reload ssh
      ```
      **Verify: open a second terminal and log in again before closing the
      first.** This is the oldest rule in server administration and it is still
      the one people skip.
- [ ] **P3-03 Clone and provision.**
      ```bash
      git clone <repo-url> /tmp/pos && cd /tmp/pos
      bash deploy/provision.sh
      ```
      Installs PHP 8.4 from the ondrej PPA, MySQL 8, nginx, Node 24, Composer,
      certbot, fail2ban and unattended upgrades; creates the `institute` account,
      the database and a scoped user; writes the FPM pool, the nginx site,
      systemd units, logrotate, 2 GB swap and both lock files; enables the
      scheduler timer; and **refuses to finish if the clock is not
      NTP-synchronised**.
      **Verify:** the script prints `Provisioning complete`.
- [ ] **P3-04 ⚠ Capture the two things it prints once.** The generated database
      password (→ **S-02**, also written to `/root/.institute-db-password`) and
      the MySQL version. The version is not trivia: Ubuntu 24.04's
      `mysql-server` resolves to the **8.0.x** line, and that is the version the
      gating CI leg must match. Record it in `docs/DEPLOYMENT.md` §7.3. **Do not
      "upgrade production to 8.4 so CI matches"** — Ubuntu's supported package is
      the lower-risk side of that trade.
- [ ] **P3-05 ⚠ The firewall Lightsail was giving us for free.** This is the real
      gap in porting the runbook. `provision.sh` §7 only ever adds ACCEPT rules
      for 80 and 443 and **explicitly defers SSH to the provider's console**
      (`deploy/provision.sh:420`). Hostinger has no such console layer switched
      on, so a box provisioned exactly as documented sits with **SSH open to the
      internet**, defended only by fail2ban. On Hostinger, `ufw` is the whole
      control.
      ```bash
      apt-get install -y ufw
      ufw default deny incoming
      ufw default allow outgoing
      ufw allow from <your-admin-ip> to any port 22 proto tcp
      ufw allow 80,443/tcp
      ufw --force enable
      ufw status verbose
      ```
      **Run this after `provision.sh`, not before** — `ufw enable` inserts its
      own chains and you want to see the result of both.
      **Verify:** `ufw status verbose` shows `deny (incoming)` as the default,
      22 restricted to your IP, 80 and 443 open, and **no 3306**. Then
      `iptables -L INPUT -n --line-numbers | head -20` to confirm the `ufw-`
      chains are in the INPUT path.
      **Two traps here:**
      **Port 80 stays open forever, TLS or not.** Certbot's HTTP-01 challenge
      renews on it every 60 days; closing it produces an expired certificate in
      November with no obvious cause.
      **Never open 3306.** MySQL is already pinned to loopback by
      `provision.sh` §3. There is no reason for it to appear in a rule.
      If your admin IP is dynamic, either use `ufw allow 22/tcp` and lean on
      fail2ban, or pin it and accept that the browser console is how you get back
      in. Pinned is better — you already opened the console at P1-06.
- [ ] **P3-06 Put the code where it runs.**
      ```bash
      sudo -u institute git clone <repo-url> /var/www/institute
      ```
      Uses the URL and credential decided at P1-08.
- [ ] **P3-07 ⚠ Copy the production template, not the local one.**
      ```bash
      cd /var/www/institute
      sudo -u institute cp .env.production.example .env
      ```
      **`.env.production.example`, never `.env.example`.** The local template
      ships `APP_ENV=local` (which exposes the passwordless demo logins),
      `APP_DEBUG=true` (which prints the database password on any error page) and
      `BACKUP_KEEP=14`. Each is separately unsafe on a live host.
      *(`docs/DEPLOYMENT.md:190` said the wrong one. Fixed — see doc bug DOC-01.)*
- [ ] **P3-08 Install PHP dependencies.** **Missing from `DEPLOYMENT.md` §13 —
      see DOC-03.** `/vendor/` is git-ignored (`.gitignore:11`), so a fresh clone
      has no autoloader and **every `artisan` command below fails without this**.
      ```bash
      sudo -u institute composer install --no-dev --optimize-autoloader --no-interaction
      ```
      **Verify:** `test -f vendor/autoload.php && echo present`
- [ ] **P3-09 ⚠ IRREVERSIBLE — generate `APP_KEY`, once, ever.**
      ```bash
      sudo -u institute php8.4 artisan key:generate
      ```
      **Then stop, and put it in the secret store (S-01) before doing anything
      else.** This key decrypts every stored TOTP secret. A database dump without
      it is not a backup — a perfect restore locks out every enrolled account and
      there is no route back in through the application. **Never rotate it after
      go-live.**
- [ ] **P3-10 Fill in the rest of `.env`.** Four values are marked FILL IN:
      `APP_KEY` (done at P3-09), `DB_PASSWORD` (from S-02), `APP_URL`
      (`https://pos.bigbinaryerp.com`), and `BACKUP_RCLONE_REMOTE` (left blank until
      P4-02).
      Set **`SESSION_SECURE_COOKIE=false`** for now.
      Confirm `INSTITUTE_CODE_PREFIX` matches D-01.
      **The ordering that locks everyone out:** a secure cookie is never sent
      over plain HTTP. Set it `true` before TLS exists and nobody can log in —
      including you, to fix it. It goes `false` here and `true` at P3-21.
      **Two values nobody predicts:** `INSTITUTE_TODAY` must be **blank** — set,
      it freezes the application's idea of "today", and every overdue date and
      this-month figure quietly stops moving while looking entirely correct. And
      `BACKUP_KEEP=168`, **not 14** — it prunes by **count**, and backups are
      hourly, so 14 means fourteen *hours* while every check stays green.
- [ ] **P3-11 Build the frontend assets.** **Missing from `DEPLOYMENT.md` §13 —
      see DOC-03.** `public/build` is git-ignored (`.gitignore:79`), and every
      layout calls `@vite(...)`, which throws on a missing manifest — so without
      this, every page is a 500.
      ```bash
      sudo -u institute npm ci
      sudo -u institute npm run build
      ```
      **Verify:** `test -f public/build/manifest.json && echo present`
      (Node 24 and Composer are both installed by `provision.sh`, so this works
      on the box. From P4 onward the pipeline builds assets in CI and the box
      never does this again.)
- [ ] **P3-12 Preflight, and read the output.**
      ```bash
      sudo -u institute php8.4 artisan deploy:preflight
      ```
      Eleven assertions, each preventing a specific failure (`§4.1`).
      **Expect exactly one failure here: `SESSION_SECURE_COOKIE`.** It is
      asserted unconditionally (`DeployPreflight.php:164`) even though §4.1
      claims it is checked "only once TLS is live" — see DOC-04. That one failure
      is correct and expected at this point, and it clears at P3-22.
      **Any other failure stops the bootstrap.** Fix it before continuing.
- [ ] **P3-13 Migrate.**
      ```bash
      sudo -u institute php8.4 artisan migrate --force
      ```
- [ ] **P3-14 Seed roles and permissions, by class name only.**
      ```bash
      sudo -u institute php8.4 artisan db:seed --class=RolePermissionSeeder --force
      ```
- [ ] **P3-15 ⚠ Seed the super admin, and capture the password.**
      ```bash
      sudo -u institute php8.4 artisan db:seed --class=SuperAdminSeeder --force
      ```
      The seeder mints a strong random password and prints it **exactly once**.
      Record it (**S-03**).
      **Never run bare `db:seed`.** It runs `DemoDataSeeder`, which invents
      students, fabricates revenue, and creates three logins whose passwords are
      committed to this repository. In a fee ledger that is not a mess to clean
      up later — it is fictional money mixed into real records.
- [ ] **P3-16 Cache the configuration.**
      ```bash
      sudo -u institute php8.4 artisan config:cache
      sudo -u institute php8.4 artisan route:cache
      sudo -u institute php8.4 artisan view:cache
      ```
      From here on `.env` is no longer read at runtime. "I changed `.env` and
      nothing happened" is almost always this.
- [ ] **P3-17 Local health check, before TLS exists.**
      ```bash
      curl -sf http://localhost/up    && echo "up OK"
      curl -sf http://localhost/ready && echo "ready OK"
      ```
      `/up` says the framework booted. `/ready` additionally proves `SELECT 1`
      reaches MySQL and the storage paths are writable.
- [ ] **P3-18 Confirm DNS actually resolves to this box.**
      ```bash
      dig +short pos.bigbinaryerp.com    # must print your VPS IP
      ```
      Certbot cannot succeed until it does.
- [ ] **P3-19 TLS.**
      ```bash
      certbot --nginx -d pos.bigbinaryerp.com
      ```
- [ ] **P3-20 Prove renewal works.**
      ```bash
      certbot renew --dry-run
      ```
      Certbot renews on a 90-day cycle nobody will be watching in November. TLS
      expiry alerting is P5-23.
- [ ] **P3-21 Now flip the cookie.**
      ```bash
      cd /var/www/institute
      sudo -u institute sed -i 's/^SESSION_SECURE_COOKIE=.*/SESSION_SECURE_COOKIE=true/' .env
      sudo -u institute php8.4 artisan config:cache
      ```
- [ ] **P3-22 Preflight again — this time it must pass clean.** Zero failures.
      If `SESSION_SECURE_COOKIE` still fails, `config:cache` did not run.
- [ ] **P3-23 Start the queue worker.**
      ```bash
      systemctl enable --now institute-queue
      systemctl status institute-queue
      ```
- [ ] **P3-24 Confirm the scheduler timer.** `provision.sh` already enabled it.
      ```bash
      systemctl status institute-scheduler.timer
      systemctl list-timers institute-scheduler.timer
      ```
- [ ] **P3-25 Public health check.**
      ```bash
      curl -sfI https://pos.bigbinaryerp.com/ready | head -1
      ```
      Monitor `/ready`, not `/up` — the first says the process is alive, the
      second says it can actually serve.
- [ ] **P3-26 Reboot once, deliberately, and re-verify.** Better to find out now
      than at 9am on a Monday.
      ```bash
      systemctl reboot
      # then, after it comes back:
      systemctl is-active institute-queue institute-scheduler.timer nginx mysql php8.4-fpm
      ufw status verbose
      curl -sfI https://pos.bigbinaryerp.com/ready | head -1
      ```
      **Verify `ufw` specifically:** `provision.sh` warns that its iptables rules
      may not persist across a reboot. `ufw` does persist — confirm it did.

---

## P4 — Backups off-box, then the pipeline

Same session as P3. The off-box copy comes **first**: everything `backup:run`
writes lands on the same disk as the database and dies with it.

- [ ] **P4-01 Configure rclone as the `institute` account**, so the scheduler can
      read the config.
      ```bash
      sudo -u institute rclone config     # Cloudflare R2: 10 GB free, no egress fees
      chmod 600 ~institute/.config/rclone/rclone.conf
      ```
      Put a copy of `rclone.conf` in the secret store (**S-05**) — losing it means
      losing access to your own backups.
- [ ] **P4-02 Point `.env` at the remote**, then re-cache.
      ```bash
      cd /var/www/institute
      sudo -u institute sed -i 's|^BACKUP_RCLONE_REMOTE=.*|BACKUP_RCLONE_REMOTE=r2:institute-backups|' .env
      sudo -u institute php8.4 artisan config:cache
      ```
- [ ] **P4-03 Prove it end to end, now.**
      ```bash
      sudo -u institute php8.4 artisan backup:run
      ```
      **Verify:** a `.sql.gz` **and** a `.json` manifest in
      `/var/backups/institute`, and `rclone lsl <remote> | tail -1` shows the
      dump landed off-box. A dump below 1 KB is treated as a failure and deleted
      — a scheduled job that goes green while writing nothing is the exact
      disaster this command exists to prevent.
- [ ] **P4-04 Set a 30-day lifecycle rule on the bucket.** On-box retention stays
      at 168 hourly dumps; the two are deliberately independent, and long
      retention belongs off-box.

**GitHub Actions.** `azure-pipelines.yml` was retired on 2026-08-29 — the Azure
DevOps organisation had no credits (`decisions.md` **D54**). The architecture is
unchanged and `deploy.sh` was not touched; only the runner moved. The box runs a
**self-hosted runner** that dials **out** to GitHub and polls for work, so there
is still no inbound deployment path and port 22 stays pinned to your own IP at
P3-05.

- [ ] **P4-05 Create the `production` Environment.** **Settings → Environments →
      New environment**, named **`production`** exactly — the workflow
      references it.
- [ ] **P4-06 Add the three protections.** These are UI settings and cannot live
      in the workflow file, which is the point: a workflow cannot meaningfully
      gate itself, because anything it said about who may approve it is
      something a pull request could change.
      | Setting | Where | Why |
      | --- | --- | --- |
      | **Required reviewers** | Environment `production` | a human presses go on money software |
      | **Deployment branches → Selected → `main`** | Environment `production` | a feature branch cannot reach production |
      | **Required status check `CI`** | Branch protection on `main` | the gate the whole matrix rolls up into |
      One-at-a-time is already in the workflow (`concurrency: production-deploy`).
      It is the first of two layers; the `flock` in `deploy.sh` is the one that
      matters, because it also covers a human who SSHes in and releases by hand.
- [ ] **P4-07 Register the self-hosted runner, as `institute`, not root.**
      **Settings → Actions → Runners → New self-hosted runner → Linux x64.**
      ```bash
      sudo -u institute -H bash
      cd ~ && mkdir -p actions-runner && cd actions-runner
      # paste the download + config commands GitHub shows, then:
      ./config.sh --url https://github.com/<owner>/<repo> \
                  --token <REGISTRATION_TOKEN> \
                  --labels institute-prod \
                  --unattended
      ```
      **The `institute-prod` label is load-bearing** — the deploy job targets
      `runs-on: [self-hosted, institute-prod]`. A runner without it is invisible
      to the workflow, and the job queues forever rather than failing, which
      reads as a hang rather than a misconfiguration.
      **As `institute`, never root.** That account holds exactly three sudo
      grants — reload `php8.4-fpm`, restart and status `institute-queue` — and
      nothing else. Installing the runner as root to "simplify the pipeline"
      hands every future edit of the workflow unrestricted control of the box.
- [ ] **P4-08 Install it as a service so it survives a reboot.**
      ```bash
      sudo ./svc.sh install institute
      sudo ./svc.sh start
      sudo ./svc.sh status
      ```
      **Verify:** the runner shows **Idle** in Settings → Actions → Runners, and
      still does after `systemctl reboot`.
- [ ] **P4-09 ⚠ The registration token expires by itself — check nothing else
      leaked.** GitHub's runner token is single-use and expires in about an
      hour, so unlike the Azure PAT there is nothing to revoke. **But confirm it
      was never committed**, and that no personal access token was used in its
      place.
- [ ] **P4-10 Add the read-only deploy key for the box.**
      **Settings → Deploy keys → Add deploy key**, paste
      `~institute/.ssh/id_ed25519.pub` from the box, and **leave "Allow write
      access" unchecked.** Then point the remote at SSH:
      ```bash
      cd /var/www/institute
      sudo -u institute git remote set-url origin git@github.com:<owner>/<repo>.git
      sudo -u institute git fetch origin        # prove it before you need it
      ```
      Never a developer PAT. PATs expire, always at the worst moment, and the
      failure looks like an unexplained deploy break months later.
- [ ] **P4-11 Prove a release end to end before enabling it.** With
      `DEPLOY_ENABLED: 'false'`, push to `main` and confirm: the matrix runs,
      `CI` goes green, `build-assets` is published, and the deploy job **refuses
      cleanly** rather than erroring. A pipeline that is red on every run is a
      pipeline nobody reads, and the cost of that is not the noise — it is a
      real failure in `CI` getting waved through on the day it matters.
- [ ] **P4-12 ⚠ Last, and deliberately a commit.**
      `.github/workflows/ci.yml` ships `DEPLOY_ENABLED: 'false'`. Flipping it to
      `'true'` is a commit rather than a click, so enabling production
      deployment appears in history with an author and a reviewer, and reverting
      is one revert. **Do not make it a `workflow_dispatch` input.** Do it only
      once the runner is registered (P4-08), the three protections are
      configured (P4-06), the deploy key works (P4-10), and P4-11 has passed.

---

## P5 — Before the counter opens

Through a **browser**, on production, with the client watching. **Never
PHPUnit** — the suite uses `RefreshDatabase` and would drop every table in
`institute_pos`.

> Reports, Staff & Roles, Courses, Settings and `/superadmin` have **never been
> driven in a browser at all**. Every round of work that included a browser pass
> found defects the test suite could not see. Budget real time for this.

- [ ] **P5-01** `https://pos.bigbinaryerp.com/ready` returns **200** publicly
- [ ] **P5-02** Super-admin login at `/superadmin` works
- [ ] **P5-03** TOTP works — an **existing** enrolment
- [ ] **P5-04** TOTP works — a **fresh** enrolment
- [ ] **P5-05** Dashboard loads
- [ ] **P5-06** Reports loads
- [ ] **P5-07** Staff & Roles loads
- [ ] **P5-08** Courses loads
- [ ] **P5-09** Settings loads
- [ ] **P5-10** Register a test student
- [ ] **P5-11** Create and inspect a challan
- [ ] **P5-12** Take an **authorised** test payment *(per D-04)*
- [ ] **P5-13** Take a **part** payment — the balance is correct
- [ ] **P5-14** Print a challan PDF
- [ ] **P5-15** Print a receipt PDF
- [ ] **P5-16** Export a spreadsheet report
- [ ] **P5-17** Dashboard and Reports **agree** on the same figures
- [ ] **P5-18** Reverse the test payment — every money figure drops by **exactly**
      that amount
- [ ] **P5-19** The receipt reprints stamped **Reversed** (not a quietly smaller
      number — the figure on a document a parent is holding does not change
      retroactively)
- [ ] **P5-20** Activity log shows all four events: enrolment, payment, reversal,
      "Deployment completed"
- [ ] **P5-21** The receipt timestamp reads **Lahore** time, not UTC. Storage is
      UTC by design; the display layer is what nine staff look at, and a
      five-hour offset on a receipt erodes confidence in a fee system on day one.
- [ ] **P5-22** "Today's collection" matches the **Lahore calendar day**
- [ ] **P5-23** External monitor on `https://pos.bigbinaryerp.com/ready`, 5-minute
      interval, **alerting on two consecutive failures, not one**. `/ready`
      returns 503 during every deploy — a monitor that pages on a single failure
      pages on every routine release and gets muted within a fortnight. Turn
      **TLS expiry alerting on** in the same monitor.
- [ ] **P5-24** Resolve the D-04 test payment exactly as agreed — reversed,
      deleted, or kept. Do not leave it undecided.

---

## P6 — After seven days of operation

The single check that catches a retention failure every other check waves
through.

- [ ] **P6-01** `ls -lt /var/backups/institute/*.sql.gz | head -1` — newest is
      under an hour old
- [ ] **P6-02 ⚠** `ls /var/backups/institute/*.sql.gz | wc -l` — prints **168**,
      not 14
- [ ] **P6-03 ⚠** `ls -t /var/backups/institute/*.sql.gz | tail -1` — oldest is
      **~7 days** old. **If it is ~14 hours, `BACKUP_KEEP` is still 14 and
      yesterday has quietly gone while every check stayed green.**
- [ ] **P6-04** `rclone lsl <remote> | sort -k2 | tail -1` — remote newest is
      under an hour old
- [ ] **P6-05** The bucket's 30-day lifecycle rule is actually configured

---

## Open items — not yours to close

Carried from `docs/DEPLOYMENT.md` §14. These need the institute, not an engineer.

- [ ] **OPEN-01 The 8 unanswered roll rows.** Two lines contradict each other on
      the same student/course; six collections exceed their fee. Keep them
      excluded until the institute answers, then dry-run, back up, and
      `roll:import --commit`. Do not guess.
- [ ] **OPEN-02 The 54 duplicate students.** Run `records:duplicates` against
      production and walk them with the institute. **No auto-merge** without
      approved rules.

---

## Doc bugs found while writing this

Four contradictions between `docs/DEPLOYMENT.md` and what the code actually does.

| ID | Where | What is wrong | Status |
| --- | --- | --- | --- |
| **DOC-01** | `DEPLOYMENT.md:190` | Said `cp .env.example .env`. Line 821 and the header of `.env.production.example` say the opposite, and they are right — the local template ships `APP_ENV=local`, `APP_DEBUG=true` and `BACKUP_KEEP=14`. | **Fixed** |
| **DOC-02** | `DEPLOYMENT.md` §11 | Pointed at `docs/DR-RUNBOOK.md`, which does not exist. The real file is `docs/PRODUCTION-EMERGENCY.md`, which is what `provision.sh` itself prints. | **Fixed** |
| **DOC-03** | `DEPLOYMENT.md` §13 steps 10–25 | Never runs `composer install` and never builds assets. Both `/vendor/` and `/public/build` are git-ignored, so on a fresh clone **step 12 (`key:generate`) fails outright** and, once past it, every page 500s on the missing Vite manifest. Corrected here as **P3-08** and **P3-11**. | **Open** |
| **DOC-05** | `DEPLOYMENT.md` §13 step 2 / P3-02 | The documented `sed` on `/etc/ssh/sshd_config` **does not disable password authentication** on this image, and fails silently. See [SSH-01](#ssh-01--why-the-documented-p3-02-would-not-have-worked). | **Open** |
| **DOC-04** | `DEPLOYMENT.md` §4.1 vs `DeployPreflight.php:164` | §4.1 says `SESSION_SECURE_COOKIE` is "asserted only once TLS is live". The code asserts it **unconditionally**, so the pre-TLS preflight at §13 step 14 always fails. Documented as expected at **P3-12**. | **Open** |

---

## SSH-01 — why the documented P3-02 would not have worked

**Found 2026-08-29 while running P3-02 for real. This is the kind of bug that
leaves you believing a control is on when it is off.**

Hostinger's Ubuntu 24.04 image ships **two conflicting drop-in files**:

```
/etc/ssh/sshd_config:130                      PermitRootLogin yes
/etc/ssh/sshd_config.d/50-cloud-init.conf:1   PasswordAuthentication yes
/etc/ssh/sshd_config.d/60-cloudimg-settings.conf:1  PasswordAuthentication no
```

`sshd` uses **the first value it obtains** for each keyword, and
`Include /etc/ssh/sshd_config.d/*.conf` sits at **line 12** of the main file —
before any of its own directives. The glob expands in lexical order, so `50-`
is read before `60-`, and `PasswordAuthentication yes` wins. `60-`'s `no` is dead
text. Effective value before the fix, straight from `sshd -T`:

```
permitrootlogin yes
passwordauthentication yes
```

**So the documented step is a no-op for the directive that matters.**
`sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/'
/etc/ssh/sshd_config` edits a line that is read *after* the drop-in that already
set it — you get a clean exit, a satisfied-looking config file, and password
authentication still enabled. (`PermitRootLogin` would have worked, since no
drop-in sets it. Half the step working is what makes this dangerous.)

### What was done instead

A drop-in that sorts **first**, so it wins under the same first-value rule:

```bash
cat > /etc/ssh/sshd_config.d/00-institute-hardening.conf <<'CONF'
# Institute POS — P3-02. Key authentication only.
# Filename sorts first on purpose: sshd takes the FIRST value it obtains for a
# keyword, and 50-cloud-init.conf sets PasswordAuthentication yes.
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
PubkeyAuthentication yes
CONF
sshd -t                  # validate BEFORE reloading, never after
systemctl reload ssh
```

It also survives cloud-init rewriting its own file, which a `sed` on either file
does not.

### Verified from outside, not by reading the config

```
$ ssh -i ~/.ssh/pos_bbt root@168.231.104.82 'whoami'
root                                          <- key auth works

$ ssh -o PubkeyAuthentication=no root@168.231.104.82
root@168.231.104.82: Permission denied (publickey).
                                    ^^^^^^^^^ password is gone from the list
```

**That second line is the actual proof.** Before the change the server answered
`(publickey,password)`. Reading `sshd -T` is a good check; being refused from
outside is the one that counts.

**Fold this into `provision.sh`** rather than leaving it as a manual step — it
has to be redone after every P2 reinstall, and a step that must be remembered
every time is a step that will eventually be forgotten.

---

## SSH-02 — the flaky connection, diagnosed and worked around

**Two separate faults, found 2026-08-29 when a `git archive | ssh` transfer
failed three times in a row while `echo ok` mostly worked.**

### Fault 1 — the path MTU is 1492, and the box was set to 1500

```
payload  MTU    result
1464     1492   OK
1472     1500   BLOCKED        <- do-not-fragment ping, 100% loss
```

The path tops out at **1492** — the classic PPPoE shape. `eth0` on the box was
at the Ethernet default of **1500**, so any full-size packet it sent was silently
discarded en route, and the ICMP that should have taught it better never arrived.
That is a **PMTU black hole**: small things work, big things vanish, and nothing
reports an error.

It explains the shape of the failures exactly. Ping (small) fine. `nc -z`
(bare SYN) fine. `echo ok` (small) usually fine. A tar stream of the whole
repository — dead, three times out of three.

```bash
ip link set dev eth0 mtu 1492      # applied 2026-08-29
```

Bulk transfer went from 0/3 to reliable immediately.

**This is not persistent yet.** It is a runtime change and will not survive the
P2-20 reinstall — or any reboot. **Fold it into `provision.sh`** alongside the
SSH-01 hardening, both being things a fresh image needs and neither being
something to remember by hand at 2am.

### Fault 2 — the provider drops TCP SYNs, and MTU did not fix it

After the MTU change, short connections still failed 5 in 8. The verbose trace
puts it before SSH is even involved:

```
debug1: Connecting to 168.231.104.82 [168.231.104.82] port 22.
debug1: connect to address 168.231.104.82 port 22: Operation timed out
```

That is the **TCP connect**, not the key exchange — so it is neither MTU nor
`sshd`. It has the signature of upstream SYN filtering or DDoS mitigation, it
survives spacing attempts out, and it is not ours to fix.

**So stop making connections.** One multiplexed session, reused:

```
# ~/.ssh/config      (added 2026-08-29)
Host pos-prod
    HostName 168.231.104.82
    User root
    IdentityFile ~/.ssh/pos_bbt
    ControlMaster auto
    ControlPath ~/.ssh/cm-%r@%h:%p
    ControlPersist 30m
    ServerAliveInterval 20
    ServerAliveCountMax 6
    ConnectionAttempts 10
```

**3 of 8 before, 10 of 10 after.** Every command since has gone over one
connection. Use `ssh pos-prod` from here on, never the raw IP.

### The rule this earns

**Run anything long — `provision.sh` above all — detached on the box, not in the
foreground of an SSH session:**

```bash
ssh pos-prod 'cd /tmp/pos && setsid nohup bash deploy/provision.sh \
    > /root/provision-run1.log 2>&1 < /dev/null &'
ssh pos-prod 'tail -f /root/provision-run1.log'      # watch separately
```

A dropped connection then costs you the view, not the run. On a link that fails
a third of the time, a half-finished `provision.sh` is the genuinely expensive
outcome — and "was it interrupted, or did it fail?" is exactly the ambiguity you
do not want at 2am.

---

## When a step fails

Quote the **step ID**. Then send, in this order:

1. **The exact command you ran** — copied, not retyped from memory
2. **The complete output**, including the lines above the error
3. **The diagnostics block below**, run on the box

```bash
sudo bash -c '
echo "=== $(date -u) ==="
echo "--- services ---";  systemctl --no-pager --lines=0 status php8.4-fpm nginx mysql institute-queue institute-scheduler.timer 2>&1 | grep -E "●|Active:"
echo "--- clock ---";     timedatectl | grep -E "Time zone|synchronized"
echo "--- disk ---";      df -h / | tail -1
echo "--- memory ---";    free -m | head -2
echo "--- listening ---"; ss -lntp | grep -E ":3306|:80 |:443 "
echo "--- firewall ---";  ufw status verbose 2>/dev/null | head -12
echo "--- backups ---";   ls -lt /var/backups/institute/*.sql.gz 2>/dev/null | head -3; ls /var/backups/institute/*.sql.gz 2>/dev/null | wc -l
echo "--- nginx log ---"; tail -30 /var/log/nginx/institute-error.log 2>/dev/null
echo "--- app log ---";   tail -40 /var/www/institute/storage/logs/laravel.log 2>/dev/null
'
```

**Never paste `.env` in full**, and never paste the output of a command that
echoes `APP_KEY`, `DB_PASSWORD` or the super-admin password. If a value is
implicated in the failure, say *which* variable is wrong — not what it is set to.

**Three failures with known answers, so you do not debug them from scratch:**

- **"I changed `.env` and nothing happened."** Almost always `config:cache`.
  Configuration is read from the cache, not from `.env`.
- **"The deploy says the lock is held."** `flock` releases on process exit, so a
  held lock means a live process. `ps -ef | grep -- deploy.sh` before assuming
  otherwise.
- **"The site is in maintenance mode and I do not know why."** That is a failed
  deploy, left that way deliberately — a half-migrated database behind a working
  login screen is worse than a maintenance page, because the counter will start
  taking money against it. Read the tail of the pipeline log, decide whether the
  pre-deploy dump needs restoring, and only then `php8.4 artisan up`. **The
  database is never auto-rolled-back.**

---

## Division of labour — what I can run, and what I cannot

I run as a shell on your workstation, inside this repository. That fixes the
boundary precisely.

**No new access needed — I can do these now.** Maintain this checklist and tick
it as we go; fix DOC-03 and DOC-04 in `docs/DEPLOYMENT.md`; prepare the exact
`.env` body with the secret lines left blank; prepare the `ufw` block and the
`.github/workflows/ci.yml` change for P4-12; read any output you paste and tell you
which step it broke at and why.

**With an SSH key and your approval, I can drive the box directly.** I would
invoke `ssh` from your machine — the private key stays in your `~/.ssh` and I
never hold a copy. That covers essentially all of P2, P3 and P4-01 to P4-04:
provisioning, the firewall, `.env`, the build, migrations, seeding, certbot,
systemd, the backup drills, and every one of the P2 refusal proofs. You approve
each command as it runs.

> **One thing to decide before that.** Anything I run, I see, and it lands in
> this session's transcript — including `APP_KEY` at P3-09, the database password
> at P3-04 and the super-admin password at P3-15. Cleanest split: I drive
> everything, and **you** personally run those three secret-capturing steps in
> your own terminal. Costs about two minutes and keeps the three fatal secrets
> out of any transcript.

**I cannot do these at all, and should not.** Buying the VPS (payment, and the
hPanel session is a collaborator view on someone else's account); anything that
is an hPanel click — the OS reinstall at P2-20, the browser console, uploading
the SSH key; the DNS record at the registrar (P1-07); the GitHub
Environment, required reviewers, branch protection and runner registration
(P4-05 to P4-11 — all browser-side settings, and the runner token is one you
generate and paste); creating the Cloudflare R2 account and bucket; TOTP enrolment and
the P5 browser pass, which is gated on an authenticator I cannot hold; and
approving a production deploy, which requires a human by design — that is the
entire point of the Approvals check at P4-10.

**The line, stated once:** anything irreversible, account-owning, or
money-touching stays with you. Everything scriptable, repeatable and verifiable
comes to me.
