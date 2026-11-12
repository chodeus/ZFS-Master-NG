# <img src="zfs.master/images/zfs.master.png" width="32" height="32" alt=""> ZFS Master NG for Unraid

See and manage your ZFS pools, datasets and snapshots from Unraid's **Main** tab.

[![CI](https://img.shields.io/github/actions/workflow/status/chodeus/ZFS-Master-NG/ci.yml?branch=beta&label=CI)](https://github.com/chodeus/ZFS-Master-NG/actions/workflows/ci.yml)
[![Unraid 6.9.2+](https://img.shields.io/badge/Unraid-6.9.2%2B-orange)](#requirements)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue)](LICENSE)

## Features

- **Pools** — health, size, used and free space, snapshot counts
- **Datasets** — create, rename, promote, destroy, and edit properties
- **Snapshots** — take, roll back, rename, clone, hold, release and delete; an icon warns when the newest gets old
- **Snapshots Admin** — filter, sort, browse a snapshot's files, or see what changed since it
- **Reviews** — destroy, rollback and bulk delete show what they'll remove first
- **Encryption** — create encrypted datasets, lock and unlock them
- **Directories** — list, move or delete folders, or convert one to a dataset (cloned instead of copied where it can)
- **Rewrite Files** — apply new compression or checksum settings to existing data (Unraid 7.3+)
- **Alerts** — optional notifications for pool problems and datasets whose newest snapshot is too old
- **ZnapZend** — optionally show each dataset's backup plans
- **Light** — no background daemon; reads ZFS only while the Main tab is open, or for alerts you turn on
- **Your view** — collapse datasets, or hide some with an exclusion pattern

## Coming next

- **Pool Info** — which Unraid can import a pool, and what would change that
- **Replication** — send datasets to another pool or server, incrementally
- **Pool history and scrubs** — recent pool commands; scrub only known errors or a date range
- **Disk latency** — slow disks in each pool, with an optional alert
- **Later** — Sanoid snapshot schedules, and changing encryption keys

## Requirements

Unraid 6.9.2 or later. ZFS is built in from 6.12; older versions need a ZFS plugin.

## Install

The current release is a beta. Go to **Plugins → Install Plugin** and paste:

```text
https://raw.githubusercontent.com/chodeus/ZFS-Master-NG/beta/zfs.master.plg
```

Renaming, editing, destroying, converting and deleting folders need **Destructive Mode** in the plugin settings, as do rollbacks and deletes that take other snapshots with them.

## Credits

- [IkerSaint](https://github.com/IkerSaint/ZFS-Master-Unraid) — original creator of ZFS Master
- [dlang123](https://github.com/dlang123/ZFS-Master-Unraid) — Unraid 7.3 port and backend rewrite

## License

GPL, version 2 or later, the same as the original ZFS Master. See [LICENSE](LICENSE).

## Diagnostics

Settings, ZFS Master NG, Diagnostics, **Generate** makes one zip for a bug report: the plugin's settings, install and log, the pools and datasets as it reads them, and what it finds wrong. Dataset, snapshot and folder names, addresses and keys are replaced by tokens; pool names stay. Unraid's own diagnostics zip goes in as Unraid made it unless you untick the box. The zip is kept in `/boot/logs`. If the page does not load, run `/usr/local/emhttp/plugins/zfs.master/scripts/diagnostics.php` in a terminal.

## Development

- `bash pkg_build.sh --version 0000.00.00 --out /tmp/zfsm` builds a package. It also rewrites the manifest's version and md5, so restore that file afterwards.
- [tests/README.md](tests/README.md) explains the test suites. They use fake `zfs` and `zpool` binaries and real Unraid webGUI code.
