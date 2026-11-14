# Changelog

## Unreleased

- fix: review round 1 — the translated Version label is escaped with the version
- fix: local review — the version is the PLUGIN element's, entities expanded as Unraid reads it; the tests check its value (three or four parts) and read Unraid's answer without its warnings
- fix: a gap between a Settings section's last control and its bottom border (Unraid 7's phone layout set it on the border)
- feat: Settings shows the installed plugin's version at the top
- fix: review round 1 — a file whose md5 can't be read fails the build, the list's entries are counted, and a full /var/log drops the line quietly
- feat: the plugin keeps its own log in RAM, capped at about 1 MB, and the package lists each file's md5
- fix: the comments call both removals of a work folder attempts
- fix: review round 8 — alerts whose schedule is not on the flash drive are a finding, and comments say what the code does
- fix: review round 7 — a sender's area with the wrong properties is a finding, and the plugin's removal step leaves what is not root's
- fix: an empty mount table blocks the removal of a work folder, and the plugin's removal step takes only the names a run makes
- fix: review round 6 — a work folder with a mount in it is never removed, and a manifest with no version is not judged
- fix: review round 5 — zpool key locations stay out of the process list, the script runs from a terminal only, and labels say what was wrong
- fix: a link is never held as a work folder, whoever owns it
- fix: review round 4 — only root's work folders are removed, and a read that fails after its file opened is a failed read
- fix: review round 3 — a read that failed is its file's result and a finding in the summary
- fix: review round 2 — a work folder is removed only under its own lock, and a run that lost its folder ends
- fix: review round 1 — a failed collector is a finding, a read that failed is never shown as empty, and a run holds its work folder
- feat: diagnostics collectors, one file each under its own time limit, and a terminal script that prints what they find
- fix: review round 20 — a killed run's part that could not be removed from /boot/logs is named in the warning, as an older zip is: one removal loop for both
- fix: review round 19 — a resume token is matched by its own shape wherever it stands, so one after -t attached or grouped (-tTOKEN, -vt TOKEN) is left out too; the scope rule says installed plugins' names, which their authors publish, are listed by design
- fix: review round 18 — the plugin's own words and paths come from the files its manifest.md5 lists, so a file the owner added is a token wherever its name stands; only the display settings the plugin uses are shown, each value only in the shape Unraid's choices take; every fake-bin test refuses to run unless /var/log is the runner's tmpfs and the host is not Unraid
- fix: review round 17 — a property read that fails, or its second read of names alone, shows only its exit code: rows it printed before the error were never checked for owner-written values and property names; the scope rule says a source that can't be read is reported in the zip, not a stop
- fix: review round 16 — every removal in /boot/logs (older zips, stale parts, a failed pack's part, Unraid's zip) goes through one check made right before it, with PHP's cached resolution of the folder cleared, so a folder swapped for a link by another process is never removed through
- fix: review round 15 — a carriage return in a name is part of it: in a file's rows a path ends only at a tab or a line feed, and the mount list and zfs's rows are split only at line feeds, so a mountpoint holding one is a token whole and its mount line one line
- fix: review round 14 — under a system folder only the system's own paths stay (the plugin's files, the Unraid files and programs it uses, what it reads), so a dataset mounted at /usr/local/client or /usr/local/12345 is a token; a test checks every system path the plugin's code names is on the list
- fix: review round 13 — Unraid's zip is removed only from /boot/logs itself, checked right before the remove, and one left there is said; older zips not removed through a link are said, and a folder that is not there is a failed look, not a link
- fix: review round 12 — a name under a system folder stays only when each of its words is the plugin's or a number, so a dataset mounted at /usr/local/x is a token, in a field, a JSON value and running text; a key's place keeps OpenZFS's own none and -
- fix: review round 11 — a path after a colon (failed:/mnt/...) or in a file:// URI is a path, an IPv6 prefix is not; a failed look for older zips is said, not taken for none; the slow-run tests wait until the run is collecting, past its work folder's lock
- Diagnostics: hide a pool's compatibility files and SELinux labels in property rows (c4b7c2d)
- fix: a JSON value with a tab is running text too, so a word after a tab is no field of its own; a failed read of the word list stays failed for the run
- fix: review round 9 — a JSON value with a space is running text and passes the second net, so a word after the plugin's own path in an inventory error is a token; a word list with a file unread, or a folder that can't be listed, stops the rewrite instead of passing as empty
- fix: review round 8 — in a row's field or a value the whole field must be one of the plugin's own names, so a folder that only starts with one is a folder's; of the saved settings only the plugin's own setting names and its one section stay
- fix: review round 7 — the plugin's own paths are the exact names it makes, its folders and the files it puts in them; nothing else inside or beside them stays; a comment moved to the function it describes
- fix: the run checks its folder against the lock the work folder was made with, before its last writes too; a folder's path end is a parameter again, not a mode left set; the swapped-folder test checks nothing of the run's reached the folder
- fix: delta review — one owner says whether the work folder is still the run's, and every step after the collectors asks it, the removal at exit too; a folder's path ends at a tab only in a file's tab-separated rows, at a line's end in running text and in a message, and nowhere in a value that is a path; the rewrite failure is tested at the script
- fix: review round 6 — in one decoded JSON value a folder's path runs to the value's end, tabs and line breaks and all; a run whose folder went under it says so and rewrites nothing; the collectors' test leaves Unraid's own diagnostics out
- fix: review round 5 — after a name prefix of the plugin's only a name it makes stays (a work folder's 16 hex digits, a job's folder, a package record), and a settings file that is not there or not read says so
- fix: review round 4 — a listed own path covers what is under it only past a / or after a name prefix it lists, and picking a zip's name and the rename hold one lock, so two runs in the same second never take one name
- fix: a dataset a setting names on a pool that does not exist is tokens wherever a collector writes it, as zfs answers too, not only in the settings list
- fix: review round 3 — a setting's first word is a pool only when this run knows that pool, so a setting registers none; a saved setting's line stays only at the value the plugin uses, and a line that sets nothing is a token; a pool's name passes the second net whole; a failed pool read is the settings file's result
- fix: property rows are read against a second read of their names alone, so a value with a line break hides every value instead of being guessed at; a user property's name is its owner's, in the rows and in the Main tab's read; only a token of this run and a whole fingerprint pass the second net
- fix: review round 2 — in logs, command lines and findings a word the plugin itself does not use is a token; a path under a listed folder stays only when it is the plugin's own; a value that runs on is hidden to the line its source ends; only a number with a unit of size or time is a plain value; a setting's bare name is a token; the tests remove only the zips they made
- fix: review round 1 — a path under /tmp, /boot, /var or /root stays only when it is the plugin's own, a pool's user properties and any line of a value that looks like a row are hidden, zip runs with no shell, the log is read once, and the tests keep to a /boot/logs of their own
- fix: a resume token and a cut-off private key are left out, each snapshot of a list and a value with a line break are tokens, a pool nothing lists is learned from the plugin's own log lines, and one of the plugin's words ends a name only with what follows it in a log line
- fix: an address kept as a user property's value, a hex-named dataset of an unknown pool, the snapshot prefix and pattern, and an unknown setting past an empty line are tokens; the zip just written is never the one removed, and a zip's name is never taken twice
- fix: the name map's and its test's comments keep to two lines
- feat: diagnostics are packed into one zip in /boot/logs with every dataset, snapshot and folder name replaced by a token, and Unraid's own diagnostics beside them as Unraid made them
- fix: a run whose work folder goes under it ends there, removing the plugin leaves a running run's folder, an inventory with no devices is a failed read, and only a sender's area is judged as one
- fix: review round 3 — a diagnostics request with no answer in a minute fails, as the inventory's does, so a stalled one reaches the retry and the not-known message
- fix: review round 2 — a request with no answer says the run's state is not known, not that it failed; a run's result that could not be kept is logged as not shown, as it is
- fix: review round 1 — the Settings page sends one request at a time and cancels the one on its way when the page is left; a run that can't make its work folder still removes the browser's report; a table drawn from the cache is recorded for diagnostics too
- fix: delta review — the browser's user agent is read by its own grammar and no browser exception is left in the shared rules; every start and every end of a run lands in the page's state (a failed start, a start under way, a run stopped by a signal); the browser's report goes on any exit; the Main tab's record keeps a share per part
- fix: code review — a run from a terminal keeps its state for the page, a start waits out another start, the browser's report is removed once collected, an address is a token except as a browser's own version, the record goes first and whole as an object, a page back from the cache asks again, Firefox's and macOS's version parts pass
- fix: pre-PR audit — the Main tab's record reads the header row's own cells; one run at a time from the page or a terminal, asked again past a status read and saying why when it can't; the start lock is not passed to the job; the record and report are bounded in bytes as sent; an idle page's failed read is no failed run; the page stops polling when it is left; a browser's words pass only in its report; the issue template says what Unraid's zip holds
- fix: audit findings — a command the diagnostics job leaves behind holds none of its locks, two starts at once are one start, the Main tab's record is bounded where it is written and the report keeps whole JSON, a lost poll is asked again, a late answer is dropped, the browser report goes through the second net, one owner for the zip's name
- feat: a Diagnostics section on the Settings page makes the zip, follows the run and links to it, with what the browser shows in it
- fix: a pool is learned only from a debug line whose command ended well, so a mistyped name stays a token; the test runner keeps the host's /boot/config; help and comments say only what holds
- fix: delta review — any command that loads a key or names where one is kept hides its arguments, wrapped or across lines (keylocation=prompt names none); pools a debug line names are learned; a diagnostics run writes no debug lines of its own; a long result never cuts off its command; the first command's time leaves out the settings read
- fix: code review — Debug Log writes a file of its own, read from the saved settings by loadConfig's rule; a line says how the command ended before the command; rsync's and key-location arguments are left out by one rule shared with the process list; a command that could not run says why; the test runs the fake zfs
- fix: a command that names where a key is kept is logged without its arguments whatever runs it, and the help, the tip and the comment say what Debug Log adds: the runner's commands and the requests; the long jobs log their own lines
- feat: Debug Log in the settings writes every command the plugin runs, with its result and time, to the plugin's own log
- fix: review round 1 — a log line proves its pools only with a success and no failure anywhere, so a name holding 'finished.' proves nothing
- refactor: the success and failure words after a dataset's name have one definition, which the proof of a pool and the name's end both read
- fix: a log line proves its pools by its first verb with what follows it, so a name holding a success word proves nothing; other lines' names still end the name before them; logs are joined line by line
- fix: diagnostics learn a pool only from a log line that started or ended well, so a mistyped destination stays a token

## 2026.11.11.3 (beta)

- Table text wraps instead of being cut off (Health, long pool names, errors)
- On Unraid 7.4, rows open the file manager from a folder, like Unraid's own pool rows

## 2026.11.11.2 (beta)

- The snapshot count shows on phones and small windows

## 2026.11.11.1 (beta)

- Replicate a dataset to another pool, in the background
- The row shows how far it got; a notification says when it finishes
- Each run sends only the changes since the last one
- Copies stay read-only and unmounted; a volume's copy is no block device
- Accept copies from other servers; sending to them comes in a later beta
- Pool Info shows which Unraid versions can import the pool
- Upgrade Pool turns on only what the pool's compatibility setting allows
- The Main table lines up with Unraid's pool tables; new Size Units setting
- Long names wrap instead of pushing the columns; row icons sit level
- A setting holding a stray non-UTF-8 byte no longer empties the page

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
