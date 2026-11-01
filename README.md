# <img src="zfs.master/images/zfs.master.png" width="32" height="32" alt=""> ZFS Master NG for Unraid

See and manage your ZFS pools, datasets and snapshots from Unraid's **Main** tab.

[![CI](https://img.shields.io/github/actions/workflow/status/chodeus/ZFS-Master-NG/ci.yml?branch=beta&label=CI)](https://github.com/chodeus/ZFS-Master-NG/actions/workflows/ci.yml)
[![Unraid 6.9.2+](https://img.shields.io/badge/Unraid-6.9.2%2B-orange)](#requirements)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue)](zfs.master/LICENSE)

## Features

- **Pools at a glance** — health, size, used and free space, and snapshot counts
- **Datasets** — create, rename, promote and destroy, and edit properties such as compression, quota, record size and access time
- **Snapshots** — take them singly or recursively, then roll back, rename, clone, hold, release or delete them, with an icon that warns when the newest one is getting old
- **Encryption** — create encrypted datasets, and lock or unlock them
- **Directories** — list the folders inside a dataset, move or delete them, or convert one to its own dataset
- **ZnapZend** — optionally show the backup plans set on each dataset
- **Light on your server** — no background daemon: it only reads ZFS while the Main tab is open, pauses when the tab is hidden, and can load snapshots after datasets or refresh only when you ask
- **Your view, your way** — collapse datasets and hide the ones you don't need, such as Docker's layers, with an exclusion pattern

## Requirements

Unraid 6.9.2 or later. ZFS is built into Unraid from 6.12; older versions need a ZFS plugin.

## Install

Available once the first beta is released. Go to **Plugins → Install Plugin** and paste:

```text
https://raw.githubusercontent.com/chodeus/ZFS-Master-NG/beta/zfs.master.plg
```

Removing datasets and directories stays off until you enable **Destructive Mode** in the plugin settings. Plain snapshot rollback and delete don't need it, so check their warnings before confirming; the options that also remove other snapshots do.

## Credits

Created by [IkerSaint](https://github.com/IkerSaint/ZFS-Master-Unraid), with Unraid 7.3 and backend work by [dlang123](https://github.com/dlang123/ZFS-Master-Unraid). Released under the GPL, version 2 or later; see [LICENSE](zfs.master/LICENSE).

## Development

- `bash pkg_build.sh --version 0000.00.00 --out /tmp/zfsm` builds a package. It also rewrites the manifest's version and md5, so restore that file afterwards.
- [tests/README.md](tests/README.md) explains the test suites. They use fake `zfs` and `zpool` binaries and real Unraid webGUI code.
