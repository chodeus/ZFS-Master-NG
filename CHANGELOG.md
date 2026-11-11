# Changelog

## Unreleased

- fix: row icons sit level with the name, with room between them
- fix: a compatibility set PCRE can't parse reads as unread
- fix: review findings — block_cloning_endian can't turn on after a clone, unknown features Upgrade Pool turns on make the answer unknown, compatibility blamed only when it limits
- refactor(pool): one step per function in the compatibility answer
- fix(pool): Upgrade Pool turns on only what the pool's compatibility setting allows
- feat(pool): Pool Info shows which Unraid can import the pool
- fix: the Replicate dialog's labels name their controls, and a new plan is announced
- fix: a resume token that can't be read keeps the run's snapshot and refuses the plan; typing a destination drops the plan in flight
- refactor: replication's plan and run in two files
- fix: audit findings — an existing bookmark of the same snapshot is no failure, send's own error wins, a taken snapshot nothing needs goes
- fix: review findings — new snapshot taken under the job's lock, bookmark length checked for every run, one plan per start, per-job row progress
- feat: Replicate dialog, row progress and the Actions entry
- wip: replication engine (local), checked live on cache/zfsm-test
- refactor: background jobs share one lock and start helper (include/ZFSMJobs.php)
- fix: the Settings warning trims the accepting dataset as the saved setting is trimmed, so a stray space no longer reads as nothing accepted
- fix: review round 1 — the checker keeps only the request and client address, the Settings page warns about an accepting dataset it won't use
- refactor: one helper names a job's lock folder, for the receive slots too
- fix: audit findings — key options matched whatever their case, from= must be exact IP addresses, the command must be exactly the installed checker, and a hello that loses a race to make the area still answers
- fix: security review findings — a stream's first record is checked before zfs receive (no clones, no -R/-p packages), copies can't be block devices or run anything, four receives per area at most, key lines that undo restrict or widen from= show in red
- fix: review findings — each sender gets its own unmounted area named in its key line, one receive whatever the stream's type, pool tops refused, settings flag a key line without restrict or from=
- feat: accept replicas from other servers into one dataset, through a key that can only list, check and receive there
- fix: review round 1 — a resumed volume run also says a device the copy has goes; the receive comment drops its test history
- fix: every volume receive takes volmode=none, and an incremental says a device the copy has goes
- fix: a volume's replica isn't a second block device of the same data (volmode=none)
- fix: a command that ends before reading its input fails with its own error, not a PHP warning about the write
- fix: review round 1 — the encoding helpers' comment keeps only the gotcha
- fix: the replica settings and key list escape and encode through zfsmHtml and zfsmJson too
- fix: review findings — a folder path holding U+FFFD is refused, zfsmJson never gives false, the CSRF fallback is JSON
- fix: a byte that isn't UTF-8 no longer empties a page's value
- fix: review findings — both units checked on every Unraid, quota in the chosen units, JS/PHP parity on null display settings
- fix: ZFS units read the same in PHP and the browser; Settings says pools show usable space; no NaN bars
- refactor: one ZFS-units formatter, in ZFSMSize.js
- fix: Actions sits centred beside the name on every Unraid version
- fix: long names and mounts wrap instead of clipping or pushing the columns
- fix: a pool's usable space needs both figures as numbers
- wip: size columns line up with Unraid's on 6.9 to 7.4
- wip: main table alignment and size units

## 2026.11.08.1 (beta)

- Rewrite Files (Unraid 7.3+) rewrites a dataset's files so new settings apply
- It runs in the background; a notification says when it finishes
- Keeping birth times is optional, and warns before it changes the pool
- A stuck ZFS can no longer hang the Main page or Snapshots Admin
- Convert reviews first and says why files would be copied, not cloned
- Convert warns when a first clone limits older Unraid to read-only
- Row icons, the fold box and Refresh work from the keyboard
- Keyboard focus stays on the same control when the page refreshes

## 2026.11.07.1 (beta)

- Snapshots Admin can filter and sort, and include child datasets' snapshots
- Browse a snapshot's files, or see what changed since it
- Destroy, delete and rollback reviews flag Sanoid/Syncoid snapshots and the boot pool
- A destroy or rollback review stops if the mount list can't be read
- New snapshot names can use UTC time
- Optional alerts for pool health problems and stale snapshots
- Convert clones files instead of copying where the pool supports block cloning
- Convert progress stays on its row, and huge folders no longer exhaust memory
- Row details show in full on phones and open from the keyboard
- Very long row details fit the window, in columns or slightly smaller
- The expand box is a pixel larger, with a gap before the name
- Snapshot browse and changes say why when ZFS can't answer

## 2026.11.06.1 (beta)

- Dialogs follow the Unraid theme: every pop-up takes the page's colours and Unraid's own buttons, on 6.9.2 to 7.x and all four themes
- Snapshots Admin is laid out like the settings pages, with held and deferred badges, the snapshot count and space used, and a message when a dataset has none
- Delete selected shows how many snapshots are ticked and stays off when none are; every snapshot action reports its result, even when the request fails
- The minus and plus icons fold a dataset; the drive icon shows its details
- Compress Ratio showed 0.01 for every dataset; it now reads as ZFS prints it, such as 1.16x
- A failed Create Dataset shows its error inside the dialog, and a dataset created with a problem still appears on the Main tab
- Pool size, used and free read like dataset sizes (1.1 TiB), and a faulted pool shows a dash instead of 0 B
- Notifications are plain text, so Discord and email no longer show HTML tags
- Errors read as one line in dialogs, notifications and the system log, and keep accented characters whole
- A zfs command that fails without output, such as one stopped by the time limit, shows a warning instead of an empty pool
- The convert prompt explains what happens to the folder

## 2026.11.05.1 (beta)

- Renamed to ZFS Master NG. It installs over ZFS Master, either the original or the 7.3 fork, and keeps your settings
- Supports Unraid 6.9.2 and newer
- A failed download or upgrade now leaves the installed version and its files on the flash drive untouched
- The old background daemon is stopped only after an upgrade succeeds, and it no longer comes back on page load
- Destroy, rollback and bulk snapshot delete show everything they will remove before they run, and stop if anything changed since you looked
- Clones, holds and pool roots are refused with the reason, instead of failing partway
- A "dataset is busy" error names the container or process using the dataset
- Admin actions and their errors go to the Unraid system log
- Settings are grouped into collapsible sections, every setting and create option has a one-line tooltip, and the help text is rewritten
- Dates use the Unraid display format
- The Create Dataset dialog is laid out like the settings page and follows the Unraid theme
- Create Dataset: read only, sync and case sensitivity inherit from the parent unless you set them, new parents get the share owner, an existing name is refused, and encryption needs a passphrase
- Dialogs follow the Unraid theme on 7.1 and later, and SweetAlert2 is updated to 11.26.25
- Works alongside other plugins on the Main tab: it no longer changes their requests, sits below the boot device, and keeps its refresh time and buttons on its title line
- A pool whose snapshots can't be read no longer blanks the others, and its rows say so. A pool that doesn't answer is retried less often, up to every 15 minutes
- Lazy loading is on unless you turned it off
- Collapsing a dataset also collapses its directories, the pool's root row stays visible, and the snapshot icons line up down the column
- On phones, the table scrolls in its own box and the plugin name stays on one line
- Shares and datasets with spaces in their names can be managed and converted
- Converting a directory keeps hardlinks and ACLs, checks the copy before it removes the original, and says where the data is if it can't finish
- Forced delete and convert refuse a directory with a mount inside it
- Saved Lua exclusion patterns keep working, and an invalid pattern is reported
- Destructive Mode is enforced on the server for rollbacks past newer snapshots and for recursive snapshot destroy
- Saving settings on a full flash drive no longer loses them, and adding or removing a directory listing entry keeps the others
- A setting Unraid would read back differently is refused with the reason, instead of being saved changed

## 2026.09.25

- Fix - Load inventory directly over HTTP without depending on Nchan, Unraid API, or the background daemon
- Fix - Preserve progressive snapshot loading, refresh intervals, and manual refresh mode
- Fix - Refresh the inventory after successful actions, including popup actions
- Fix - Remove unnecessary nginx reloads during plugin installation and removal
- Fix - Show status for disabled refresh, failed requests, missing data, and connection errors
- Fix - Tolerate unavailable or corrupt browser cache without stopping live rendering
- Fix - Clear the daemon filesystem stat cache before checking refresh and configuration changes
- Fix - Rebuild the archive from current source, including the popup CSRF fixes

## 2026.09.03

- Fix - Replaced Lua channel-program inventory with machine-readable OpenZFS CLI queries
- Fix - Correct dataset hierarchy, root-dataset snapshots, lazy snapshot totals, and query error reporting
- Fix - Dataset, snapshot, encryption, property, and directory operations use argument-safe process execution
- Fix - Snapshot delete reporting, rename support, and explicit rollback of newer snapshots
- Fix - PHP 8.4 compatibility, daemon resilience, output escaping, and request validation
- Fix - Unraid 7.3.2 packaging, CSRF integration, Nchan startup, and installer handling

## 2024.12.08

- Add - Config for pulling ZnapZend plans
- Fix - Refresh and settings icon
- Fix - Corner case if no information is loaded

## 2024.11.17

- Add - ZnapZend plans information

## 2024.11.11.87

- Fix - Lazy load bug

## 2024.11.11

- Fix - Style for Unraid 7
- Add - Properties extraction for ZFS Volumes
- Fix - for folder listing corner cases

## 2024.05.05

- Fix - Malicious content in SweetAlert2 package. Thanks @Ubsefor
- Add - Initial support for ZFS Vols (Just detection, more is coming)
- Fix - Exclussion patterns keywords

## 2024.03.31

- Fix - Compatibility with 6.13 beta
- Change - Convert to dataset reporting on dataset row
- Add - Unraid notifications for Convert to Dataset
- Fix - for folder listing corner cases
- Fix - for zvols

## 2024.03.03

- Fix - Dataset name validation before convertion
- Fix - rsync command for folders with whitespaces or special characters
- Fix - Errors on empty exclusion patterns
- Add - Compatibility with 6.13 beta

## 2024.02.15

- Fix - Directory Listing not Working

## 2024.02.10

- Add - "-X" option for Connvert Dataset rsync command 
- Fix - Directory Listing detecting datasets as folders

## 2024.02.09

- Add - Convert directory to dataset functionality
- Add - Written property for snapshots
- Add - Directory listing for root datasets
- Fix - Tabbed view support
- Fix - Configuration file associated errors
- Fix - Units nomenclature
- Fix - Pool information parsing errors
- Remove - Unraid Notifications 

## 2023.12.08

- Add - Directory Listing functionality
- Fix - Optimize multiple operations

## 2023.12.04

- Fix - Used and Free % bars/texts are now consistent with unraid theme and config
- Fix - Set time format for the last refresh to short date and time
- Fix - Detect Pools with used % under 0%
- Fix - ZPool regex not caching some pools with dots or Underscore in the name

## 2023.10.07

- Add - Cache last data in Local Storage when using "no refresh"
- Fix - Dataset admin Dialog - Error on select all datasets 
- Fix - Multiple typos
- Fix - Special condition crashing the backend
- Fix - Status refresh on Snapshots admin dialog
- Change - Date format across multiple dialogs
- Change - Local Storage for datasets and pools view options

## 2023.09.27

- Change - "No refresh" option now doesn't load information on page refresh
- Fix - Dynamic Config reload

## 2023.09.25

- Fix - Config load 
- Fix - Exclusion patterns for datasets with spaces
- Fix - Destroy dataset functionality

## 2023.09.25

- Add - Lazy load functionality
- Add - Nchan for updates
- Add - Refresh options (Including On demand)
- Add - Last refresh timestamp
- Add - Dynamic refresh after an operation
- Change - Migration to zfs programs for most operations
- Change - Quota setting on Create Dataset Dialog
- Change - Notifications and messages improvement
- Change - Edit datasets UI as a dropdown menu
- Fix - Dataset passphrase input not masked
- Fix - ZPool regex not caching some pools
- Fix - Dataset passphrase size difference
- Fix - Multiple typos
- Fix - PHP 8 Compatibility

## 2023.07.04

- Fix - Dataset names with spaces not being properly handled

## 2023.04.03

- Add - Snapshots admin UI
- Add - Rename datasets UI
- Add - Edit datasets UI
- Add - unRaid 6.12 compatiblity 
- Add - Lazy load for snapshots admin UI
- Fix - Improve PHP 8 Compatibility

## 2023.03.07

- Fix - PHP 8 Upgrades
- Change - Sanpshots admin UI
- Add - Rename datasets capabilities
- Add - Lazy load for snapshots admin UI

## 2023.02.28

- Fix - PHP 8 Upgrades
- Fix - Export pool command
- Fix - Error on parsing dataset origin property

## 2022.12.04

- Fix - Error on counting childs

## 2022.11.12

- Fix - Error on dialogs and input controls
- Add - Clone capabilities for snapshots
- Add - Promote capabilities for datasets

## 2022.11.05

- Fix - Error on pools with snapshots but without datasets
- Fix - Dialogs not sizing properly
- Add - Snapshot Batch Deletion

## 2022.08.21

- Change - UI into "folder" structure
- Add - Support for ZFS Encryption
- Add - Unlock and Lock actions for encrypted datasets
- Fix - Error on unRaid 6.9.2 associated with session management

## 2022.08.02

- Warning - Please Update your exclusion pattern!
- Add - Browse Button for Datasets
- Add - Support for listing volumes!!
- Add - Lua script backend for loading dataset information
- Change - Exclusion pattern for datasets (Please check http://lua-users.org/wiki/PatternsTutorial)
- Change - UI columns re-organized to the unraid way (sort of)

## 2022.04.13

- Add - Dataset Snapshot Creation Option
- Add - Settings for Snapshot Creation (pattern and prefix)
- Change - "Destroy" and "Snapshots" buttons merged to "Actions"

## 2022.04.10

- Add - Dataset Snapshot management (rollback, hold, release, destroy)
- Fix - Installation script bug

## 2022.04.08

- Add - Set permissions for new Datasets

## 2021.11.09

- Add - List of current Datasets at Dataset Creation
- Add - Option for export a Pool (In construction)
- Fix - Compatibility with unRAID RC versions

## 2021.10.08

- Add - SweetAlert2 for notifications
- Add - Refresh and Settings Buttons
- Add - Mountpoint information for Pools
- Add - Configurable Settings for Refresh Time, Destructive Mode, Dataset Exclusions, Alert Max Days Snapshot Icon 
- Fix - Compatibility with Other Themes (Dark, Grey, etc.)
- Fix - Improper dataset parsing
- Fix - Regex warnings
- Fix - UI freeze error on some system at destroying a Dataset
- Remove - Unassigned Devices Plugin dependency

## 2021.10.04

- Initial Release.
