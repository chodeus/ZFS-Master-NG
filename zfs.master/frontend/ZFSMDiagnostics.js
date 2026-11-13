/* The Settings page's Diagnostics section: starts a run, follows it, and links to its zip. */

// What only the browser knows: itself, its window, and what the plugin keeps in it (zfsmDiagRecord has the Main tab's last render).
// Whole JSON within 50,000 characters of kept values: a value that does not fit is left out and counted, never cut through.
function zfsmDiagReport(win) {
	const kept = {};
	let room = 50000, leftOut = 0;
	try {
		for (let index = 0; index < win.localStorage.length; index++) {
			const key = win.localStorage.key(index);
			if (!/^(zfsm-|zdataset-)/.test(key)) continue;
			const value = String(win.localStorage.getItem(key));
			// The cache is the whole inventory, which the run reads for itself; the record is bounded where it is written.
			const shown = key === 'zfsm-cache' ? value.length + ' characters' : key === 'zfsm-diagnostics' ? value : value.slice(0, 2000);
			if (shown.length > room) leftOut++;
			else kept[key] = shown;
			room -= Math.min(room, shown.length);
		}
	} catch (error) {
		kept.unreadable = String(error);
	}
	return JSON.stringify({userAgent: String(win.navigator.userAgent).slice(0, 500), window: [win.innerWidth, win.innerHeight], pixelRatio: win.devicePixelRatio,
		theme: (win.document.documentElement.className + ' ' + win.document.body.className).trim().slice(0, 200), kept: kept, leftOut: leftOut});
}

// options: url (the admin route), download (the link's address before the zip's name), button, box, status, link, report().
function createZFSMDiagnostics(options) {
	let busy = false, asked = 0, lost = 0;

	function show(answer) {
		busy = answer.state === 'running' || answer.state === 'started';
		options.button.prop('disabled', busy);
		options.link.hide();
		if (busy) options.status.text('Collecting. This can take a few minutes.');
		else if (answer.state === 'done') {
			options.status.text('Ready, and kept in /boot/logs' + (answer.why ? ' (' + answer.why + ')' : '') + ': ');
			options.link.attr('href', options.download + encodeURIComponent(answer.zip)).text(answer.zip).show();
		} else if (answer.state === 'failed') options.status.text('Diagnostics failed: ' + (answer.why || 'no reason was given'));
		else options.status.text('');
	}

	// An old Unraid answers a request whose token is stale with nothing at all: that is no answer.
	function unanswered(xhr) {
		show({state: 'failed', why: 'the server did not answer (HTTP ' + xhr.status + '). Reload the page and try again.'});
	}

	// Only the newest request's answer is shown: one that comes back after a later request was sent is stale.
	function ask(data, done, fail) {
		const ticket = ++asked;
		$.post(options.url, data, function(answer) { if (ticket === asked) done(answer); }, 'json').fail(function(xhr) { if (ticket === asked) fail(xhr); });
	}

	// While a run goes, a poll the server did not answer is asked again, three times, before the run is called failed.
	function poll() {
		ask({cmd: 'diagnosticsstatus'}, function(answer) {
			lost = 0;
			show(answer || {});
			if (busy) setTimeout(poll, 2000);
		}, function(xhr) {
			if (!busy || ++lost > 3) return unanswered(xhr);
			options.status.text('Collecting. The server did not answer; asking again.');
			setTimeout(poll, 2000);
		});
	}

	function start() {
		if (busy) return;
		lost = 0;
		show({state: 'started'});
		ask({cmd: 'diagnosticsstart', unraid: options.box.prop('checked') ? '1' : '0', report: options.report()}, function(answer) {
			if (answer && answer.state === 'failed') show(answer);
			else poll();
		}, unanswered);
	}

	return {start: start, poll: poll};
}
