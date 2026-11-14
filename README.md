# email_ratelimit — per-mailbox rate limiting for ISPConfig 3.3 + Rspamd

Rate limit **incoming and outgoing** mail per mailbox, per domain, server-wide, and
per external address or domain — with a GUI in the ISPConfig panel.

ISPConfig has no native mail rate limiting. This extension adds it as a proper
ISPConfig extension, replicating the feature set described in
[schaal-it's "Rate limit for incoming and outgoing mails"](https://schaal-it.dev/en/blog/rate-limit-for-incoming-and-outgoing-mails/)
as a self-contained addon.

* Targets **ISPConfig 3.3+** with `content_filter = rspamd` and Redis (the standard
  3.3 mail stack — nothing extra to install).
* **No core ISPConfig file is modified.** It uses the extension framework, the
  documented plugin events, the theme template override search path, and Rspamd's
  official `rspamd.local.lua` entry point. `git diff` of the panel stays empty,
  and ISPConfig updates do not clobber it.

---

## What it does

| Direction | Meaning |
|-----------|---------|
| **Outgoing** | messages sent **by** an authenticated mailbox (SASL user). This is the limit that stops a compromised account from blasting spam. |
| **Incoming** | messages delivered **to** a mailbox (per recipient). |

Limits are set in four places, each overriding the one above:

1. **Server wide** — `System > Server Config > Mail > Ratelimit`
2. **Per domain** — `Mail > Domain` → *Ratelimit* panel
3. **Per mailbox** — `Mail > Email Mailbox` → *Ratelimit* panel
4. **Explicit external limits** — `Mail > Ratelimit` (a domain like `@example.com`
   or a single address like `user@example.com`, direction *Incoming*, *Outgoing*
   or *In and Out*)

### Behaviour

* **Per recipient counting** — a message to 3 recipients counts as 3 for incoming.
* **Per-window counters** — every limit owns a fixed window (1 minute … 1 day).
  Keys embed the interval and the window number (`erl:<interval>:<window>:…`), so
  a new window starts empty with no cron job; the TTL only cleans up stale keys.
* **Only accepted mail counts** — the counter is checked and incremented
  atomically, so a rejected message never consumes quota. A message with several
  recipients can still spend quota for the recipients checked before the one that
  tripped the limit.
* **Soft reject (4xx), never a hard bounce** — the sending server queues and
  retries; nothing is lost.
* **Fail open** — if Redis is unreachable, mail is allowed. A counter outage
  never blocks mail.
* **Unlimited is the default** — nothing is limited until you enable it and set
  a limit greater than zero. A per-domain or per-mailbox limit is enforced even
  when the server-wide default for that direction is `0`.
* **External rules match the remote party** — *Incoming* is matched against the
  envelope sender, *Outgoing* against a destination recipient.
* **Unauthenticated mail is not counted** against outgoing limits (no SASL user
  → no outgoing bucket).

### Scope and limitations

* **External rules are administrator-only.** `Mail > Ratelimit` is server wide, so
  the page and its menu entry are restricted to admin accounts; a reseller cannot
  throttle another tenant's mail. Per-domain and per-mailbox limits remain
  available to resellers for their own domains.
* **Locally injected mail is not covered.** Outgoing limits key on the SASL user,
  so mail sent through `sendmail`, PHP `mail()` or a cron script is not counted.
  That closes the compromised-mailbox path, not the compromised-website path.
* **Incoming limits count spam too.** The limit caps everything delivered to a
  mailbox; it does not filter. Set it generously or leave it at `0`.
* **Single Redis only** (not Redis Cluster — the check script needs its key and
  TTL in one hash slot).

---

## Requirements

* ISPConfig **3.3+** (tested against 3.3.2p1)
* Rspamd as the Postfix content filter (`System > Server Config > Mail > Content Filter = Rspamd`)
* Redis (already required by Rspamd in a standard ISPConfig 3.3 setup)
* The installer adds a guarded two-line `dofile()` block to
  `/etc/rspamd/rspamd.local.lua` — the generated configuration **and** the
  enforcement module. Both files must exist: Rspamd aborts its config load on a
  `dofile()` of a missing path.
* Postfix wired to Rspamd as a milter — this is what ISPConfig does for rspamd,
  and it is what makes the SASL user visible to Rspamd:
  `smtpd_milters = inet:localhost:11332`, `milter_mail_macros = ... {auth_authen}`

Single Redis only (not Redis Cluster — the atomic check script needs both keys in
one hash slot).

## Install

```bash
# 1. place the extension in the extension directory
cp -a email_ratelimit /usr/local/ispconfig/extensions/

# 2. deploy it
cd /usr/local/ispconfig/extensions/email_ratelimit
php install/deploy.php install
```

> **Why not `ispc extension install`?** That command resolves the extension name
> against the online ISPConfig extension repository and only installs published
> extensions. `deploy.php` drives the same installer class and SQL files
> locally, which is the supported path for a privately built extension.

`install` performs, in order:

1. adds the `erl_*` columns to `server`, `mail_domain`, `mail_user`
2. deploys the files (see `install/file.list`) and creates the two `plugin.d`
   directories and two theme template directories that do not exist in a stock install
3. creates (and verifies) the `email_ratelimit` table in the master database;
   the installer exits non-zero if any SQL statement fails
4. appends the guarded `dofile()` block for the configuration *and* the module
   to `/etc/rspamd/rspamd.local.lua`, keeping a `.email_ratelimit.bak` copy of your file
5. generates `/etc/rspamd/email_ratelimit.conf.lua`, deploys
   `/etc/rspamd/email_ratelimit.lua` and reloads Rspamd

Then, in the panel:

* `System > Server Config > Mail` → **Ratelimit**: tick it, set *Ratelimit Mails Out*
  and the interval. Leave *Ratelimit Mails In* at `0` to leave receiving unlimited.
* optionally set per-domain and per-mailbox limits.
* optionally add external limits under `Mail > Ratelimit`.

### Staged rollout (recommended)

Rate limiting can surprise you if a limit is too low, so start by watching:

```bash
# see the config the panel generated
cat /etc/rspamd/email_ratelimit.conf.lua

# watch the counters live
redis-cli --scan --pattern 'erl:*'
redis-cli --scan --pattern 'erl:*' | xargs -r -n1 redis-cli get

# watch decisions
tail -f /var/log/rspamd/rspamd.log | grep erl
```

`erl ok ...` is an accepted, counted message. `erl DEFER ...` is a soft reject
that hit the limit.

## Updating

```bash
cd /usr/local/ispconfig/extensions/email_ratelimit
php install/deploy.php update
```

Updates are idempotent. If a file this extension owns has been changed by
something else, the previous content is kept as `<file>.email_ratelimit.bak`.

## Disable / uninstall

```bash
php install/deploy.php disable     # stop enforcing, keep everything in place
php install/deploy.php uninstall   # remove files, columns and the table
```

`uninstall` removes: the deployed files, the symlinks, the `erl_*` columns, the
`email_ratelimit` table, the generated Rspamd files and the `rspamd.local.lua`
include line. It **never touches core files** — the language and template
overrides are all new files of its own.

Redis counters are left behind (harmless, they expire); clear them with:

```bash
redis-cli --scan --pattern 'erl:*' | xargs -r redis-cli del
```

## Troubleshooting

| Symptom | Check |
|---------|-------|
| Nothing is limited | `erl_enabled` in `System > Server Config > Mail` must be **on**, and either the relevant server limit **> 0** or a per-mailbox/external rule must exist. Then check both `dofile()` lines are in `/etc/rspamd/rspamd.local.lua` and `cat /etc/rspamd/email_ratelimit.conf.lua` shows `enabled = true`. |
| No `erl ok` / `erl DEFER` lines in the log at all | The module is not loaded. `grep email_ratelimit /etc/rspamd/rspamd.local.lua` must show two `dofile()` lines; `rspamadm configtest` must be clean. Re-run `php install/deploy.php update`. |
| `redis error … allowing mail` on every message | Rspamd cannot run the check script. Verify Redis is reachable and that the module deployed by `update` is the current one (`erl ok` lines should appear within one message). |
| Config on disk is stale | The cron job (`100-email_ratelimit.inc.php`, every 10 min) repairs a config that drifted while the server process was down. `/var/log/ispconfig/ispconfig.log` shows `email_ratelimit: configuration regenerated`. |
| Outgoing limit never triggers | Rspamd must see the SASL user. Confirm the milter macros include `{auth_authen}` (see Requirements) — without it every message looks unauthenticated and outgoing limits are skipped. |
| Incoming limit triggers on spam | Expected. An incoming limit caps everything delivered to a mailbox, it does not filter spam. Set it generously or leave it at `0`. |
| Everything is allowed (fail open) | Redis is unreachable. `redis-cli ping`. This is deliberate — mail is never blocked by a counter outage. |
| Panel shows no Ratelimit panel | The theme template override or the `plugin.d` file did not deploy. Re-run `php install/deploy.php update` and check `/usr/local/ispconfig/interface/web/mail/lib/plugin.d/`. If you use a theme other than `default`, copy the three files from `themes/default/templates/{mail,admin}/` into your theme's `templates/` directory. |

## How it works

```
ISPConfig panel                    Server process                  Rspamd
───────────────                    ──────────────                  ──────
server_config / mail_domain /      email_ratelimit_plugin.inc.php
mail_user forms
  │ on_after_formdef injects         │ on server_*/mail_*_* events
  │ the erl_* fields                 │
  │                                  ▼
  │ core tform saves the tab fields   │ resolves effective limits
  │ (the erl_* columns)              │
  ├─ Mail > Ratelimit CRUD ────────► │ renders ERL_CONFIG Lua table
    (own table, datalog hook)        │ writes /etc/rspamd/*.conf.lua
                                     ▼
                              rspamd.local.lua ──dofile──► email_ratelimit.lua
                                                            │ prefilter ERL_CHECK
                                                            ▼
                                              atomic check+incr against Redis
                                              soft reject (4xx) when over limit
```

Limits are stored in columns on the **core** `server`, `mail_domain` and
`mail_user` tables, because ISPConfig already replicates those to every mail
server — so a multiserver setup needs nothing extra to propagate them.

The extension's own `email_ratelimit` table (the *Mail > Ratelimit* external
limits) is created by `install_master.sql` and therefore only exists in the
**master** database. On a slave it is read through `$app->dbmaster`, and
`install/master_grants.list` declares the `SELECT` right the slave's
`ispcsrv<id>` MySQL user needs on it. Note: on ISPConfig 3.3.1 nothing reads that
file — it targets a newer extension framework. On a multi-server setup, grant the
right manually (`GRANT SELECT ON db.email_ratelimit TO 'ispcsrv<id>'@'…'`), or
`deploy.php` runs as root on the master and the table simply is not replicated.

`install/file.list` is the file manifest. Note that ISPConfig's
`extension_installer` has `enable_files()`/`disable_files()` methods but nothing
in the core calls them, so the manifest is applied by this extension's own
`install()`/`uninstall()`.

## Layout

```
install/
  installer.php              install/update/enable/disable/uninstall + file deployment
  deploy.php                 CLI entry point (run from the extension directory)
  file.list                  file manifest: <action>:<source>:<target>
  install_master.sql         the email_ratelimit table
  uninstall_master.sql
  master_grants.list

server/
  lua/email_ratelimit.lua                        enforcement module (rspamd prefilter)
  lib/classes/email_ratelimit_sync.inc.php       effective-limit resolution + config generation
  lib/classes/cron.d/100-email_ratelimit.inc.php safety-net repair job
  plugins-available/email_ratelimit_plugin.inc.php  regenerates config on changes
  mods-available/email_ratelimit_module.inc.php     hooks the extension's table

interface/web/
  mail/email_ratelimit_{list,edit,del}.php       Mail > Ratelimit CRUD
  mail/form/email_ratelimit.tform.php
  mail/list/email_ratelimit.list.php
  mail/lib/menu.d/email_ratelimit.menu.php       menu entry
  mail/lib/plugin.d/email_ratelimit_{domain,user}.inc.php    form fields + persistence
  admin/lib/plugin.d/email_ratelimit_server.inc.php
  themes/default/templates/mail/*.htm            template overrides (domain + mailbox)
  themes/default/templates/admin/*.htm           template override (server config)
```

## Development

The enforcement module is plain Lua (Lua 5.1 / LuaJIT, as embedded by Rspamd) and
is covered by a dependency-free harness with an Rspamd API stub, so it can be
tested without a mail server:

```bash
for c in global_out override_only override_in extern_out extern_out_dedupe \
         extern_in soft_reject no_check_when_unlimited disabled; do
  lua5.1 tests/ratelimit_spec.lua "$c" server/lua/email_ratelimit.lua || exit 1
done

php tests/test_installer_php.php   # SQL loader + rspamd.local.lua block handling
```

The Lua tests pin the behaviour that matters: the Redis call shape (`keys` vs
`args`), one script registration, per-mailbox overrides with the global default
off, per-window keys and TTLs, external rules matching the remote party,
de-duplication per message, soft reject, and the idle/disabled paths.

When changing `server/lua/email_ratelimit.lua`, re-run `php install/deploy.php update`
so the deployed copy is refreshed — the sync class installs it to
`/etc/rspamd/email_ratelimit.lua`.
