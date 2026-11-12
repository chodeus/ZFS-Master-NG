/* The Settings page's Diagnostics section: starts a run, follows it, and links to its zip. */

// What only the browser knows: itself, its window, and what the plugin keeps in it (zfsmDiagRecord has the Main tab's last render).
function zfsmDiagReport(win) {
	const kept = {};
	try {
		for (let index = 0; index < win.localStorage.length; index++) {
			const key = win.localStorage.key(index);
			if (!/^(zfsm-|zdataset-)/.test(key)) continue;
			const value = String(win.localStorage.getItem(key));
			// The cache is the whole inventory, which the run reads for itself.
			kept[key] = key === 'zfsm-cache' ? value.length + ' characters' : value.slice(0, 2000);
		}
	} catch (error) {
		kept.unreadable = String(error);
	}
	return JSON.stringify({userAgent: win.navigator.userAgent, window: [win.innerWidth, win.innerHeight], pixelRatio: win.devicePixelRatio,
		theme: (win.document.documentElement.className + ' ' + win.document.body.className).trim(), kept: kept}).slice(0, 60000);
}

// options: url (the admin route), download (the link's address before the zip's name), button, box, status, link, report().
function createZFSMDiagnostics(options) {
	let busy = false;

	function show(answer) {
		busy = answer.state === 'running' || answer.state === 'started';
		options.button.prop('disabled', busy);
		options.link.hide();
		if (busy) options.status.text('Collecting. This can take a few minutes.');
		else if (answer.state === 'done') {
			options.status.text('Ready, and kept in /boot/logs: ');
			options.link.attr('href', options.download + encodeURIComponent(answer.zip)).text(answer.zip).show();
		} else if (answer.state === 'failed') options.status.text('Diagnostics failed: ' + (answer.why || 'no reason was given'));
		else options.status.text('');
	}

	// An old Unraid answers a request whose token is stale with nothing at all: that is no answer.
	function unanswered(xhr) {
		show({state: 'failed', why: 'the server did not answer (HTTP ' + xhr.status + '). Reload the page and try again.'});
	}

	function poll() {
		$.post(options.url, {cmd: 'diagnosticsstatus'}, function(answer) {
			show(answer || {});
			if (busy) setTimeout(poll, 2000);
		}, 'json').fail(unanswered);
	}

	function start() {
		if (busy) return;
		show({state: 'started'});
		$.post(options.url, {cmd: 'diagnosticsstart', unraid: options.box.prop('checked') ? '1' : '0', report: options.report()}, function(answer) {
			if (answer && answer.state === 'failed') show(answer);
			else poll();
		}, 'json').fail(unanswered);
	}

	return {start: start, poll: poll};
}
