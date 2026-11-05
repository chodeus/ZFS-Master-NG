# Changelog

## Unreleased

- Renamed to ZFS Master NG. It installs over ZFS Master, either the original or the 7.3 fork, and keeps your settings
- Supports Unraid 6.9.2 and newer
- A failed download or upgrade now leaves the installed version and its files on the flash drive untouched
- The old background daemon is stopped only after an upgrade succeeds, and it no longer comes back on page load
- fix: give each dataset and directory its own menu id
- fix: let folders with spaces convert to datasets, and encode dataset row ids
- fix: keep inventory warnings on the status line when showing cached data
- fix: collapse a dataset's directories with it, and keep the pool root row visible
- fix: refuse to force-delete or convert a directory that is a mount point
- fix: refuse conversions a dataset cannot hold and read Lua patterns as Lua
- fix: keep data safe in directory, snapshot and exclusion handling
- fix: keep a named branch's update check in test builds
- fix: report a new dataset left without the share owner instead of skipping it silently
- fix: judge cache completeness from the merged answer, so a pool that recovers in its own read is cached
- fix: scroll the table in its own box on Unraid 7.2+ phones, and keep failed dataset reads out of the cache
- fix: on phones, drop the status under the title so the plugin name keeps one line
- fix: count a dataset as missing only on ZFS's closing message, not a name that contains it
- fix: create each missing parent on its own, stop reads on pagehide, find the title line on Unraid 6.9, and escape page JSON
- fix: refuse an existing dataset name or an unreadable parent, keep encryption on, and keep full-answer snapshot errors out of the cache
- fix: never claim an existing parent on a failed read, and keep failed snapshot counts out of the cache
- fix: make create options do what they say, and run the backend command tests
- fix: refuse any encryption value but yes or no, mark unread snapshot counts, and open hints from the keyboard
- fix: show each hint when hovering its label, with the help cursor
- feat: one-line tooltips on every setting and create option, and refuse encryption without a passphrase
- fix: give the create dialog's single dropdowns the same width as its text fields
- fix: keep the create dialog's rows on one line and style its text fields like Unraid's
- feat: lay the create dialog out like the settings page, and drop unused styles and scripts
- feat: group the settings into collapsible sections
- fix: give the create dialog's fields their widths and the selects room for the arrow
- fix: lay the create dialog out in rows and colour it from the theme
- fix: style the dialogs with Unraid's theme on 7.1 and later
- fix: put the refresh time and buttons on the ZFS Master NG title line
- fix: keep the snapshot count and browse icon level with the camera
- fix: line the snapshot camera icons up down the column
- fix: behave on the shared Main tab across Unraid versions
- fix(deps): refuse any audio or locale hook in a SweetAlert2 update, and keep that list in one place
- fix: keep dialog layout and current values under SweetAlert2 11.26, and test the page's dialog handlers
- fix(deps): keep the dialogs' look under Unraid's themes when updating SweetAlert2
- fix(deps): update SweetAlert2 to 11.26.25 and keep it in sync safely
- fix: skip the clone list when a rollback removes nothing, and test that bulk delete destroys only after the confirm
- fix: tie a destroy review token to its mode, so a review without force can't confirm a forced destroy
- perf: check clones and holds with a lookup, not a scan per row
- fix: tie a review token to the dataset or snapshot that was reviewed
- fix: let the snapshot delete route delete only snapshots, and keep the tests off the real /mnt/user
- fix: hold a bulk snapshot delete to the list that was reviewed, and delete it in one command
- feat: review what a destroy or rollback removes, and name what keeps a dataset busy
- fix: change the directory listing one request at a time, so an overlapping add or remove is not lost
- fix: refuse a carriage return in a saved setting instead of dropping it, and correct the refresh and exclusion help
- fix: refuse to save a setting Unraid would not read back as written, instead of joining the directory listing
- fix: keep a saved setting that holds a quote on one line, so a line break cannot start a new setting
- feat: show dates in the Unraid display format, and rewrite the settings help
- fix: keep every directory listing entry, and never lose settings to a short write
- fix: say when the busy check could not list a dataset's mountpoints, and keep checking a namespace whose first process exits mid-read
- fix: say where a converted directory's data is when a failed create leaves it unable to move back
- refactor: bring every function under CodeFactor's complexity line, fix the copy-progress in-operator guard, and drop eval from the page test

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
