// Icons that act as buttons (role=button) take a button's keys: Enter presses on keydown and Space on keyup, only on the
// control it went down on, so moving focus with Space held presses nothing.
function zfsmButtonKeys(selector) {
	let spaceOn = null;
	$(document).on('keydown keyup', selector, function(event) {
		if (event.key !== 'Enter' && event.key !== ' ') return;
		event.preventDefault();
		const down = event.type === 'keydown';
		// A held key repeats its keydown on whatever has focus by then: only the first one counts.
		if (down && event.originalEvent && event.originalEvent.repeat) return;
		if (event.key === 'Enter') {
			if (down) this.click();
			return;
		}
		if (down) spaceOn = this;
		else if (spaceOn === this) { spaceOn = null; this.click(); }
	});
	$(document).on('focusout', selector, function() { if (spaceOn === this) spaceOn = null; });
}
