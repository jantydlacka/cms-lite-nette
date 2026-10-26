// The initial theme is set by the inline script in <head>; this only handles the toggle.
document.addEventListener('DOMContentLoaded', function () {
	var button = document.querySelector('[data-testid="theme-toggle"]');
	if (!button) {
		return;
	}
	var root = document.documentElement;
	var sync = function () {
		button.setAttribute('aria-pressed', root.getAttribute('data-theme') === 'dark' ? 'true' : 'false');
	};
	sync();
	button.addEventListener('click', function () {
		var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
		root.setAttribute('data-theme', next);
		sync();
		try {
			localStorage.setItem('cms-theme', next);
		} catch (e) {}
	});
});
