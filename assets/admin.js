(function () {
	'use strict';

	var config = window.SlugSyncAdmin || {};

	function initAutoContinue() {
		var next = document.getElementById('slug-sync-next');
		var timer;

		if (!next) {
			return;
		}

		function stopAuto(event) {
			if (event.target.closest && event.target.closest('.slug-sync-stop-form')) {
				window.clearTimeout(timer);
				next.setAttribute('data-auto-stopped', '1');
			}
		}

		/* Stop controls later in the document (including run history) count
		 * too. Delegation also covers keyboard and assistive-technology use. */
		document.addEventListener('focusin', stopAuto);
		document.addEventListener('pointerdown', stopAuto);
		document.addEventListener('submit', stopAuto);

		timer = window.setTimeout(function () {
			if (!next.hasAttribute('data-auto-stopped')) {
				next.submit();
			}
		}, 5000);
	}

	function initStartForm() {
		var form = document.getElementById('slug-sync-start-form');
		var text = config.text || {};
		var hierarchical = config.hierarchical || {};
		var button;
		var writeHelp;
		var applyNote;
		var safety;
		var typeSelect;
		var hierarchyNote;

		if (!form) {
			return;
		}

		button = document.getElementById('slug-sync-start-button');
		writeHelp = document.getElementById('slug-sync-write-help');
		applyNote = document.getElementById('slug-sync-apply-note');
		safety = document.getElementById('slug-sync-safety');
		typeSelect = document.getElementById('slug-sync-post-type');
		hierarchyNote = document.getElementById('slug-sync-hierarchy-note');

		function applying() {
			var selected = form.querySelector('input[name="mode"]:checked');
			return selected && selected.value === 'apply';
		}

		function update() {
			var isApply = applying();

			button.textContent = isApply ? text.apply_button : text.preview_button;
			writeHelp.textContent = isApply ? text.apply_write : text.preview_write;
			applyNote.hidden = !isApply;

			if (safety) {
				safety.hidden = !isApply;
			}

			if (typeSelect && hierarchyNote) {
				hierarchyNote.hidden = !hierarchical[typeSelect.value];
			}
		}

		form.addEventListener('change', function (event) {
			if (event.target.name === 'mode' || event.target.name === 'post_type') {
				update();
			}
		});

		form.addEventListener('submit', function (event) {
			if (applying() && !window.confirm(text.confirm_apply)) {
				event.preventDefault();
			}
		});

		update();
	}

	function initConfirmations() {
		document.addEventListener('submit', function (event) {
			var form = event.target;
			var message;

			if (!form.matches || !form.matches('.slug-sync-confirm-form')) {
				return;
			}

			message = form.getAttribute('data-confirm');

			if (message && !window.confirm(message)) {
				event.preventDefault();
			}
		});
	}

	function initProSlider() {
		var slider = document.getElementById('slug-sync-pro-slider');
		var previous = document.getElementById('slug-sync-pro-prev');
		var next = document.getElementById('slug-sync-pro-next');
		var current = document.getElementById('slug-sync-pro-current');
		var cards = slider ? slider.children : [];

		if (!slider || !cards.length || !previous || !next || !current) {
			return;
		}

		function metrics() {
			var gap = parseFloat(window.getComputedStyle(slider).columnGap) || 0;
			var step = cards[0].getBoundingClientRect().width + gap;
			var visible = Math.max(1, Math.floor((slider.clientWidth + gap + 1) / step));
			return { step: step, visible: visible };
		}

		function update() {
			var size = metrics();
			var start = Math.min(cards.length - 1, Math.max(0, Math.round(slider.scrollLeft / size.step)));
			var end = Math.min(cards.length, start + size.visible);

			current.textContent = (start + 1) + (end > start + 1 ? '\u2013' + end : '');
			previous.disabled = start === 0;
			next.disabled = end >= cards.length;
		}

		function move(direction) {
			var size = metrics();
			slider.scrollBy({ left: direction * size.step * size.visible, behavior: 'smooth' });
		}

		previous.addEventListener('click', function () {
			move(-1);
		});
		next.addEventListener('click', function () {
			move(1);
		});
		slider.addEventListener('scroll', update, { passive: true });
		window.addEventListener('resize', update);
		update();
	}

	initConfirmations();
	initAutoContinue();
	initStartForm();
	initProSlider();
}());
