/* Inventory uses authenticated HTTP; Nchan is optional copy-progress only. */
// What a status line must add to a timestamp, whether the data is live or cached.
function zfsmInventoryNotes(data) {
    const errors = Object.values(data.errors || {});
    return (errors.length ? ' — Query errors: ' + errors.join('; ') : '') + (data.warning ? ' — ' + data.warning : '');
}

function createZFSMInventoryController(options) {
    let config = options.config;
    let busy = false;
    let pending = false;
    let timer = null;
    let stopped = false;
    let failures = 0;

    // Back off while reads fail: a pool too slow to list must not be queried back to back.
    function schedule() {
        clearTimeout(timer);
        const interval = Number(config.refresh_interval);
        if (!stopped && interval > 0) {
            const seconds = Math.min(Math.max(interval, 1) * 2 ** failures, Math.max(interval, 900));
            timer = setTimeout(function() {
                if (document.hidden) schedule();
                else refresh();
            }, seconds * 1000);
        }
    }

    function finish() {
        busy = false;
        options.spinner(false);
        if (pending && !stopped) {
            pending = false;
            refresh();
        } else schedule();
    }

    function failed(message) {
        if (stopped) return;
        failures++;
        options.status(message + ' Existing rows may be stale.', true);
        finish();
    }

    function validate(answer) {
        if (!answer || !['getAll', 'getDatasets'].includes(answer.op) || !answer.data ||
            !answer.data.pools || !answer.data.datasets || !answer.data.devices) {
            const errors = answer && answer.failed ? Object.values(answer.failed).join('; ') : 'Invalid inventory response';
            throw new Error(errors);
        }
        for (const pool of Object.keys(answer.data.pools)) {
            if (!answer.data.datasets[pool] || !Object.prototype.hasOwnProperty.call(answer.data.devices, pool)) {
                throw new Error('Incomplete inventory for ' + pool);
            }
        }
    }

    function request(data, done, onFailure = failed) {
        $.ajax({url: options.url, method: 'POST', dataType: 'json', timeout: 60000,
            data: Object.assign({csrf_token: options.csrf}, data)
        }).done(function(answer) {
            if (stopped) return;
            try { validate(answer); done(answer); }
            catch (error) { onFailure('Unable to load ZFS inventory: ' + error.message); }
        }).fail(function(xhr, status) {
            onFailure('ZFS inventory request failed (HTTP ' + xhr.status + ', ' + status + ').');
        });
    }

    function refresh() {
        if (stopped) return;
        if (busy) { pending = true; return; }
        clearTimeout(timer);
        busy = true;
        options.spinner(true);
        options.status('Reading ZFS datasets and snapshots…', false);
        request({cmd: 'inventory'}, function(answer) {
            config = Object.assign({}, config, answer.config || {});
            options.configChanged(config);
            const data = answer.data;
            const pools = answer.op === 'getDatasets' ? Object.keys(data.pools) : [];
            let snapshotsMissing = false;
            let snapshotsIncomplete = false;
            Object.keys(data.pools).forEach(answered);
            options.render(data, false);

            function unavailable(pool) {
                data.pools[pool].Snapshots = 'Unavailable';
                (function mark(dataset) {
                    dataset._snapshots_unavailable = true;
                    Object.values(dataset.child || {}).forEach(mark);
                })(data.datasets[pool]);
            }

            // A pool that answered with a snapshot error is not slow, but its counts must not be cached.
            function answered(pool) {
                if (data.datasets[pool]._snapshot_error) { snapshotsIncomplete = true; unavailable(pool); }
            }

            function complete() {
                options.render(data, !snapshotsMissing && !snapshotsIncomplete);
                // A pool that answers with a query error is not slow, so only unanswered reads back off.
                failures = snapshotsMissing ? failures + 1 : 0;
                let message = 'Last refresh at ' + new Date().toLocaleString('en-US', {hour12: false});
                if (answer.publishing_paused) message += ' — Unraid push updates paused; inventory loaded directly.';
                const notes = zfsmInventoryNotes(data);
                options.status(message + notes, notes !== '');
                finish();
            }

            function nextPool() {
                if (!pools.length) { complete(); return; }
                const pool = pools.shift();
                options.status('Reading snapshots for ' + pool + '…', false);
                request({cmd: 'inventorysnapshots', pool: pool}, function(part) {
                    if (!part.data.pools[pool] || !part.data.datasets[pool]) throw new Error('Missing snapshot inventory for ' + pool);
                    ['pools', 'datasets', 'devices'].forEach(key => { data[key][pool] = part.data[key][pool]; });
                    data.errors = data.errors || {};
                    delete data.errors[pool];
                    Object.assign(data.errors, part.data.errors || {});
                    answered(pool);
                    options.render(data, false);
                    nextPool();
                }, function(message) {
                    if (stopped) return;
                    // One slow pool must not leave the others without snapshots.
                    snapshotsMissing = true;
                    data.errors = data.errors || {};
                    data.errors[pool] = message;
                    data.datasets[pool]._snapshot_error = message;
                    unavailable(pool);
                    try {
                        options.render(data, false);
                        nextPool();
                    } catch (error) {
                        failed('Unable to show ZFS inventory: ' + error.message);
                    }
                });
            }
            nextPool();
        });
    }

    return {refresh: refresh, stop: function() { stopped = true; pending = false; clearTimeout(timer); }};
}

// Called in the main page and both popup documents, so even manual-refresh mode
// updates after a successful mutation. Failed/read-only requests do not rescan.
function installZFSMActionRefresh(refresh) {
    $(document).ajaxSuccess(function(event, xhr, settings, answer) {
        if (!settings.url || !settings.url.includes('/plugins/zfs.master/backend/ZFSMAdmin.php')) return;
        if (typeof answer === 'string') {
            try { answer = JSON.parse(answer); } catch (_) { return; }
        }
        if (answer && answer.succeeded && Object.keys(answer.succeeded).some(key => key !== 'refresh')) refresh();
    });
}
