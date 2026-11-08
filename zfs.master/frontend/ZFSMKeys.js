// Icons that act as buttons (role=button) take a button's keys: Enter presses on keydown, once however long it is held,
// and Space on keyup, only on the control it went down on, so moving focus with Space held presses nothing.
function zfsmButtonKeys(selector) {
	let spaceOn = null;
	$(document).on('keydown keyup', selector, function(event) {
		if (event.key !== 'Enter' && event.key !== ' ') return;
		event.preventDefault();
		const down = event.type === 'keydown';
		if (event.key === 'Enter') {
			if (down && !(event.originalEvent && event.originalEvent.repeat)) this.click();
			return;
		}
		if (down) spaceOn = this;
		else if (spaceOn === this) { spaceOn = null; this.click(); }
	});
	$(document).on('focusout', selector, function() { if (spaceOn === this) spaceOn = null; });
}
