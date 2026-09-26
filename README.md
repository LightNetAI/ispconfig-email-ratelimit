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
* **Only accepted mail counts** — the counter is checked and incremented
  atomically, so a rejected burst consumes no quota.
* **Soft reject (4xx), never a hard bounce** — the sending server queues and
  retries; nothing is lost.
* **Counters reset at midnight** with no cron job. Keys are date-scoped
  (`erl:YYYY-MM-DD:...`) with a TTL for cleanup.
* **Fail open** — if Redis is unreachable, mail is allowed. A counter outage
  never blocks mail.
* **Unlimited is the default** — nothing is limited until you enable it and set
  a limit greater than zero.
* **Unauthenticated mail is not counted** against outgoing limits (no SASL user
  → no outgoing bucket).

---

## Requirements

* ISPConfig **3.3+** (tested against 3.3.2p1)
* Rspamd as the Postfix content filter (`System > Server Config > Mail > Content Filter = Rspamd`)
* Redis (already required by Rspamd in a standard ISPConfig 3.3 setup)
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
3. creates the `email_ratelimit` table
4. appends one guarded `dofile(...)` line to `/etc/rspamd/rspamd.local.lua`
5. generates `/etc/rspamd/email_ratelimit.conf.lua` and reloads Rspamd

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
| Nothing is limited | `erl_enabled` in `System > Server Config > Mail` must be **on**, and the relevant limit **> 0**. Then `cat /etc/rspamd/email_ratelimit.conf.lua` and confirm `enabled = true`. |
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
  │ tab plugin onInsert/onUpdate → email_ratelimit_sync
  │ writes erl_* columns             │ resolves effective limits
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
`install/master_grants.list` declares the `SELECT` right the slave's `ispcsrv<id>`
MySQL user needs on it (applied by ISPConfig's own `setrights.php`, so it
survives ISPConfig updates).

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

The enforcement module is plain Lua and can be tested without a mail server. The
test harness used while building this extension lives outside the package:
a faithful rspamd API stub plus a real `redis-server`, driving the real module
through 27 assertions (limits, overrides, isolation, multi-recipient counting,
fail-open, day-scoped reset, disabled directions).

When changing `server/lua/email_ratelimit.lua`, re-run `php install/deploy.php update`
so the copy in `/usr/local/ispconfig/server/lua/` is refreshed — the sync class
installs it to `/etc/rspamd/email_ratelimit.lua`.
