# Valkey / Redis cPanel Plugin

A cPanel plugin that empowers individual cPanel users to manage their own
**Valkey** (or Redis-compatible) cache instance — completely self-service,
no root access required.

![Plugin demo](https://github.com/windsofchange/redis-cpanel-plugin/blob/main/Redis_cPanel_Plugin_By_Atik.gif?raw=true)

## Key Features

- **Per-user isolation** — each cPanel account gets its own instance with unique credentials
- **Valkey-first** — auto-detects `valkey-server` (BSD-licensed); falls back to `redis-server`
- **Self-service UI** — Start / Stop / Reset directly from cPanel → Software
- **Secure by default** — config `0600`, log dir `0700`, 32-char random password, `protected-mode yes`
- **Pure cache mode** — `maxmemory-policy allkeys-lru` + persistence disabled (`save ""`)
- **Unix socket + TCP** — low-latency local connections via socket, plus TCP port
- **Copy-to-clipboard** — one-click credential and connection string copying
- **Framework snippets** — ready-to-use PHP, WordPress, and Laravel connection examples
- **@reboot cron** — instance auto-starts after a server reboot (no duplicate entries)
- **CSRF protection** — all mutating actions require POST + session token

## Prerequisites

- cPanel/WHM (Jupiter theme) — tested on cPanel 128+
- AlmaLinux 9 / CloudLinux 9
- **Valkey installed** (recommended) or Redis-compatible binary:

```bash
# Valkey (preferred — BSD-licensed, no per-instance fee)
dnf install valkey

# Create redis-server compatibility symlinks
ln -sf /usr/bin/valkey-server    /usr/bin/redis-server
ln -sf /usr/bin/valkey-cli       /usr/bin/redis-cli
ln -sf /usr/bin/valkey-benchmark /usr/bin/redis-benchmark

# Disable the system-wide service — per-user instances only
systemctl disable valkey --now
```

## Installation

```bash
# As root on the cPanel server:
git clone https://github.com/windsofchange/redis-cpanel-plugin.git
cd redis-cpanel-plugin
chmod +x ./plugin/install.sh
./plugin/install.sh
```

The installer will verify that a Redis-compatible binary is available before
proceeding, and will exit with a clear error message if not.

## CloudLinux — CageFS

If CageFS is enabled on the server, register Valkey (and the compatibility
symlinks) so they are accessible inside user jails:

```bash
# Register the Valkey RPM
cagefsctl --addrpm valkey
cagefsctl --force-update

# Expose the manually-created redis-server symlinks inside CageFS
# Create /etc/cagefs/conf.d/redis-compat.cfg with the following content:
```

`/etc/cagefs/conf.d/redis-compat.cfg`:
```
[redis-compat]
comment=Redis compatibility symlinks for Valkey
paths=/usr/bin/redis-server,/usr/bin/redis-cli,/usr/bin/redis-benchmark
```

Then update the skeleton:
```bash
cagefsctl --force-update
```

> **Why?** `cagefsctl --addrpm valkey` only picks up files owned by the RPM.
> The `redis-server` symlinks you created manually are not RPM-owned, so they
> need an explicit `conf.d` entry to appear inside user jails.

## Usage

After installation, cPanel users navigate to:

**cPanel → Software → Valkey / Redis**

From there they can:
- Start their personal Valkey instance (auto-generates a unique password + port)
- Stop it when not needed
- Copy connection credentials to clipboard
- View ready-made connection snippets for PHP, WordPress, and Laravel
- Reset (delete) the instance and all its data

## Uninstall

```bash
/usr/local/cpanel/scripts/uninstall_plugin \
    /usr/local/cpanel/base/frontend/jupiter/redis_plugin --theme=jupiter

rm -rf /usr/local/cpanel/base/frontend/jupiter/redis_plugin
```

See the [cPanel plugin uninstall guide](https://api.docs.cpanel.net/guides/guide-to-cpanel-plugins/guide-to-cpanel-plugins-uninstall-plugins) for more details.

## Environment Tested

| Platform          | cPanel Version |
|-------------------|----------------|
| AlmaLinux 9       | 128.x          |
| CloudLinux 9      | 128.x          |

## Changelog

### v2.0
- Valkey-first binary detection (`valkey-server` → `redis-server` fallback)
- Fixed hardcoded `/bin/redis-server` path
- Config file `0600`, log dir `0700`, config dir `0750`
- Added `maxmemory-policy allkeys-lru`, `save ""`, `loglevel notice`, `protected-mode yes`
- Unix socket support alongside TCP port
- `escapeshellarg()` on all shell invocations
- PID process-name validation before SIGTERM
- `@reboot` cron (was `* * * * *`); duplicate-entry guard via `cronExists()`
- Password increased to 32 hex chars (was 16)
- New `getStatus()`, `getConnectionInfo()`, `resetRedis()` methods
- Removed debug output visible to end users
- CSRF protection on all mutating actions (POST + session token)
- Copy-to-clipboard for credentials and code snippets
- Framework connection snippets: PHP, PHP/socket, WordPress, Laravel
- Reset button for full instance teardown
- Updated icon reference to `.webp`; menu label "Valkey / Redis"
- `install.sh` hardened with `set -euo pipefail` and pre-flight checks
- `meta.json` updated to cPanel 128
- README updated with Valkey prerequisites and CageFS conf.d instructions

### v1.0
- Initial release

## License

Licensed under the [Apache License 2.0](http://www.apache.org/licenses/LICENSE-2.0).

## Contributing

Pull requests and issues are welcome via GitHub.

## Disclaimer

Please verify in a staging environment before deploying to production.
Use at your own risk.
