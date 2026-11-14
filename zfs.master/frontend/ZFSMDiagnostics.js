/* The Settings page's Diagnostics section: starts a run, follows it, and links to its zip. */

// What only the browser knows: itself, its window, and what the plugin keeps in it (zfsmDiagRecord has the Main tab's last render).
// Whole JSON: kept values take 50,000 bytes at most as sent, well under the 64 KB the server keeps; one that does not fit is counted.
function zfsmDiagReport(win) {
	const kept = {};
	let room = 50000, leftOut = 0;
	try {
		const keys = [];
		for (let index = 0; index < win.localStorage.length; index++) keys.push(win.localStorage.key(index));
		// The Main tab's record first, as itself: it is what the report is for, and it is bounded where it is written (zfsmDiagRecord).
		keys.sort((one, other) => (other === 'zfsm-diagnostics') - (one === 'zfsm-diagnostics'));
		for (const key of keys) {
			if (!/^(zfsm-|zdataset-)/.test(key)) continue;
			const value = String(win.localStorage.getItem(key));
			// The cache is the whole inventory, which the run reads for itself.
			const shown = key === 'zfsm-cache' ? value.length + ' characters' : key === 'zfsm-diagnostics' ? zfsmDiagParsed(value) : value.slice(0, 2000);
			const size = zfsmDiagBytes(JSON.stringify(shown));
			if (size > room) leftOut++;
			else {
				kept[key] = shown;
				room -= size;
			}
		}
	} catch (error) {
		kept.unreadable = String(error);
	}
	return JSON.stringify({userAgent: String(win.navigator.userAgent).slice(0, 500), window: [win.innerWidth, win.innerHeight], pixelRatio: win.devicePixelRatio,
		theme: (win.document.documentElement.className + ' ' + win.document.body.className).trim().slice(0, 200), kept: kept, leftOut: leftOut});
}

// The record as the object it is; one that is not JSON is text like any other value.
function zfsmDiagParsed(value) {
	try {
		return JSON.parse(value);
	} catch (error) {
		return value.slice(0, 2000);
	}
}

// A text's size in UTF-8 bytes, as it is sent; a text that can't be sent as UTF-8 (a lone surrogate) has no size that fits.
function zfsmDiagBytes(text) {
	try {
		return encodeURIComponent(text).replace(/%[0-9A-F]{2}/g, '_').length;
	} catch (error) {
		return Infinity;
	}
}

// options: url (the admin route), download (the link's address before the zip's name), button, box, status, link, report().
function createZFSMDiagnostics(options) {
	let busy = false, asked = 0, lost = 0, timer = null, pending = null;

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

	// An old Unraid answers a request whose token is stale with nothing at all (HTTP 200): that is no answer either.
	function failure(xhr) {
		return xhr.status && xhr.status !== 200 ? 'the server answered HTTP ' + xhr.status : 'the server did not answer' + (xhr.status ? ' (HTTP 200)' : '');
	}

	// No answer says nothing of the run: it may go on, or never have started. "Failed" is only the server's word.
	function unanswered(xhr) {
		show({});
		options.status.text('The run\'s state is not known: ' + failure(xhr) + '. Reload the page to see it.');
	}

	function later() {
		timer = setTimeout(poll, 2000);
	}

	// One request at a time: one still on its way when the next is sent is cancelled, and only the newest one's answer is shown.
	// One with no answer in a minute fails, as the inventory's does (ZFSMInventory.js).
	function ask(data, done, fail) {
		const ticket = ++asked;
		if (pending) pending.abort();
		pending = $.ajax({url: options.url, method: 'POST', data: data, dataType: 'json', timeout: 60000});
		pending.done(function(answer) { if (ticket === asked) done(answer); });
		pending.fail(function(xhr) { if (ticket === asked) fail(xhr); });
	}

	// While a run goes, a poll the server did not answer is asked again, three times, before the run's state is called not known.
	// With no run going, only the state could not be read.
	function poll() {
		ask({cmd: 'diagnosticsstatus'}, function(answer) {
			lost = 0;
			show(answer || {});
			if (busy) later();
		}, function(xhr) {
			if (!busy) return options.status.text('The state of diagnostics could not be read: ' + failure(xhr) + '.');
			if (++lost > 3) return unanswered(xhr);
			options.status.text('Collecting. The server did not answer; asking again.');
			later();
		});
	}

	// The page is left: no more polls, and a request still on its way is cancelled.
	function stop() {
		clearTimeout(timer);
		asked++;
		if (pending) pending.abort();
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

	return {start: start, poll: poll, stop: stop};
}
