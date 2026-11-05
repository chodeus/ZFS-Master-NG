// Load right after sweetalert2.all.min.js: every dialog then takes the page's colours and Unraid's own buttons.
(function() {
	if (typeof window.Swal2 === 'undefined') return;
	const transparent = /^(transparent|rgba\(0, 0, 0, 0\))$/;

	// Unraid 6.x themes set no colour variables, so read what the page really shows.
	function pageColours() {
		for (const element of [document.body, document.documentElement]) {
			const style = getComputedStyle(element);
			if (!transparent.test(style.backgroundColor)) return {background: style.backgroundColor, color: style.color};
		}
		return null;
	}

	// SweetAlert's own variables, so every part of the dialog (checkbox labels, footers) follows too.
	function themeDialog(popup) {
		const colours = pageColours();
		if (!colours) return;
		popup.style.setProperty('--swal2-background', colours.background);
		popup.style.setProperty('--swal2-color', colours.color);
	}

	window.Swal2 = window.Swal2.mixin({buttonsStyling: false, customClass: {popup: 'zfsm-dialog'}, willOpen: themeDialog});
})();
