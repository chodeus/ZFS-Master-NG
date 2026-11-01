# ZFS Master for Unraid

Information and control over the ZFS pools on your Unraid server, under **Main → ZFS Master**: datasets, snapshots, encryption, and directory-to-dataset conversion.

Requires Unraid **6.9.2 or newer** with a ZFS pool. Before 6.12, ZFS comes from the ZFS plugin.

## Install

Available once the first beta is released. **Plugins → Install Plugin**, then paste:

```text
https://raw.githubusercontent.com/chodeus/ZFS-Master-Unraid/beta/zfs.master.plg
```

Your existing `zfs.master` settings carry over. Destructive actions stay off until you enable **Destructive Mode** in the plugin settings.

## Credits

Created by [IkerSaint](https://github.com/IkerSaint/ZFS-Master-Unraid), with Unraid 7.3 and backend work by [dlang123](https://github.com/dlang123/ZFS-Master-Unraid). GPL-2.0-or-later; see `zfs.master/LICENSE`.

## Development

- `bash pkg_build.sh --version 0000.00.00 --out /tmp/zfsm` builds a package and rewrites the manifest's version and md5, so restore it afterwards.
- Tests are described in [tests/README.md](tests/README.md). They use fake `zfs` and `zpool` binaries.
