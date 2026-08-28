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

	var SCROLL_KEY = 'slugSyncModalScroll';

	function forget() {
		try {
			window.sessionStorage.removeItem(SCROLL_KEY);
		} catch (error) {
			/* Private windows refuse session storage. Nothing here needs it. */
		}
	}

	/* Batches advance by reloading the page, so a reader who has scrolled
	 * down the overlay is thrown back to the top every few seconds. Browsers
	 * restore the document's scroll, never an element's, so the overlay has
	 * to carry its own across the reload. */
	function keepScroll(modal) {
		var pending = false;
		var stored;

		try {
			stored = window.sessionStorage.getItem(SCROLL_KEY);
		} catch (error) {
			return;
		}

		if (stored) {
			modal.scrollTop = parseInt(stored, 10) || 0;
		}

		modal.addEventListener('scroll', function () {
			if (pending) {
				return;
			}

			pending = true;
			window.requestAnimationFrame(function () {
				pending = false;

				try {
					window.sessionStorage.setItem(SCROLL_KEY, String(modal.scrollTop));
				} catch (error) {
					/* Storage filled or refused; the scroll simply resets. */
				}
			});
		}, { passive: true });
	}

	function initRunModal() {
		var modal = document.getElementById('slug-sync-run-modal');
		var card;
		var close;
		var backdrop;

		if (!modal) {
			return;
		}

		card = document.getElementById('slug-sync-modal-card');
		close = document.getElementById('slug-sync-modal-close');
		backdrop = modal.querySelector('.slug-sync-modal-backdrop');

		document.body.classList.add('slug-sync-modal-open');

		/* A run still working has no close. Stop run is the way out of it,
		 * and the admin bar is deliberately left uncovered. */
		if (modal.getAttribute('data-state') === 'running') {
			keepScroll(modal);
			return;
		}

		forget();

		if (card) {
			card.focus();
		}

		function dismiss(event) {
			var heading = document.querySelector('.slug-sync-admin .slug-sync-brand');

			if (event) {
				event.preventDefault();
			}

			modal.hidden = true;
			document.body.classList.remove('slug-sync-modal-open');

			/* The overlay was rendered by the server, so there is no trigger to
			 * hand focus back to. Without this it lands on the body and a
			 * keyboard user resumes from nowhere. */
			if (heading) {
				heading.tabIndex = -1;
				heading.focus();
			}
		}

		/* Without script the close is a plain link back to the screen, which
		 * still reaches the reports through Previous runs. With script it is
		 * cheaper to reveal the page already rendered behind it. */
		if (close) {
			close.addEventListener('click', dismiss);
		}

		if (backdrop) {
			backdrop.addEventListener('click', dismiss);
		}

		document.addEventListener('keydown', function (event) {
			if (event.key === 'Escape' && !modal.hidden) {
				dismiss(event);
			}
		});
	}

	function initWorkflowSteps(form) {
		var steps = Array.prototype.slice.call(form.querySelectorAll('[data-slug-sync-step]'));

		if (!steps.length) {
			return;
		}

		function openNext(target) {
			var current = target.closest ? target.closest('[data-slug-sync-step]') : null;
			var index = current ? steps.indexOf(current) : -1;
			var next = index >= 0 ? steps[index + 1] : null;

			if (!next || target.disabled) {
				return;
			}

			next.open = true;
			next.classList.add('is-auto-opened');
			window.setTimeout(function () {
				next.classList.remove('is-auto-opened');
			}, 700);
		}

		form.addEventListener('change', function (event) {
			if (event.target.matches && event.target.matches('input, select')) {
				openNext(event.target);
			}
		});

		/* Clicking an already-selected radio is still a deliberate choice and
		 * should advance, even though browsers do not emit change in that case. */
		form.addEventListener('click', function (event) {
			if (event.target.matches && event.target.matches('input[type="radio"]:checked')) {
				openNext(event.target);
			}
		});
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
		var skuOptions;
		var skuUnavailable;

		if (!form) {
			return;
		}

		initWorkflowSteps(form);

		button = document.getElementById('slug-sync-start-button');
		writeHelp = document.getElementById('slug-sync-write-help');
		applyNote = document.getElementById('slug-sync-apply-note');
		safety = document.getElementById('slug-sync-safety');
		typeSelect = document.getElementById('slug-sync-post-type');
		hierarchyNote = document.getElementById('slug-sync-hierarchy-note');
		skuOptions = document.getElementById('slug-sync-sku-options');
		skuUnavailable = document.getElementById('slug-sync-sku-unavailable');

		function applying() {
			var selected = form.querySelector('input[name="mode"]:checked');
			return selected && selected.value === 'apply';
		}

		function update() {
			var isApply = applying();
			var isProduct = typeSelect && typeSelect.value === (config.productType || 'product');

			button.textContent = isApply ? text.apply_button : text.preview_button;
			writeHelp.textContent = isApply ? text.apply_write : text.preview_write;
			applyNote.hidden = !isApply;

			if (safety) {
				safety.hidden = !isApply;
			}

			if (typeSelect && hierarchyNote) {
				hierarchyNote.hidden = !hierarchical[typeSelect.value];
			}

			if (skuOptions) {
				skuOptions.disabled = !isProduct;
				skuOptions.classList.toggle('is-disabled', !isProduct);
				skuOptions.setAttribute('aria-disabled', isProduct ? 'false' : 'true');
			}

			if (skuUnavailable) {
				skuUnavailable.hidden = !!isProduct;
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
	initRunModal();
	initAutoContinue();
	initStartForm();
	initProSlider();
}());
