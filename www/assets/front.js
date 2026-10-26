// The initial theme is set by the inline script in <head>; this only handles the toggle.
document.addEventListener('DOMContentLoaded', function () {
	var button = document.querySelector('[data-testid="theme-toggle"]');
	if (!button) {
		return;
	}
	button.addEventListener('click', function () {
		var root = document.documentElement;
		var next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
		root.setAttribute('data-theme', next);
		try {
			localStorage.setItem('cms-theme', next);
		} catch (e) {}
	});
});
