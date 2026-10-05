/**
 * Krivo ChatFlow loader. Runs (deferred) on every page that shows a widget and does only what is
 * cheap: read the page's JSON config, apply the visitor-side rules (device, delay, scroll), reveal
 * the bubble that PHP already printed, and download the panel on the first sign of intent.
 *
 * Nothing here talks to third parties. Colours are applied through the CSS object model (no style
 * attribute, no style tag) so a strict Content Security Policy keeps working.
 */
import { applyBubbleStyle } from '../shared/bubble-style.js';

(function () {
	'use strict';

	var doc = document;
	var win = window;
	if (win.KrivoChatFlow && win.KrivoChatFlow.version) {
		return;
	}
	var node = doc.getElementById('krivo-chatflow-config');
	if (!node) {
		return;
	}
	var cfg;
	try {
		cfg = JSON.parse(node.textContent);
	} catch (e) {
		return;
	}

	var PREFIX = 'krivo_chatflow';
	var panelPromise = null;
	var bubbleApi = null;

	function safe(storage) {
		try {
			var probe = '__kcf';
			storage.setItem(probe, '1');
			storage.removeItem(probe);
			return storage;
		} catch (e) {
			return null;
		}
	}
	var session = safe(win.sessionStorage);
	var local = safe(win.localStorage);
	function get(store, key) {
		try {
			return store ? store.getItem(PREFIX + ':' + key) : null;
		} catch (e) {
			return null;
		}
	}
	function set(store, key, value) {
		try {
			if (store) {
				store.setItem(PREFIX + ':' + key, value);
			}
		} catch (e) {
			/* storage may be full or blocked: the widget works without it */
		}
	}

	function device() {
		var w = win.innerWidth || doc.documentElement.clientWidth;
		return w < 768 ? 'mobile' : w < 1025 ? 'tablet' : 'desktop';
	}

	function emit(name, detail) {
		var full = PREFIX + '_' + name;
		try {
			doc.dispatchEvent(new CustomEvent(full, { detail: detail, bubbles: true }));
			if (cfg.datalayer && win.dataLayer && typeof win.dataLayer.push === 'function') {
				var payload = { event: full };
				payload[PREFIX] = detail;
				win.dataLayer.push(payload);
			}
		} catch (e) {
			/* an analytics listener must never break the widget */
		}
	}

	// First-touch campaign parameters, kept for the session only (no cookies, no identifiers).
	(function captureUtm() {
		if (get(session, 'utm') || !win.location.search) {
			return;
		}
		var params = new URLSearchParams(win.location.search);
		var utm = {};
		var found = false;
		['source', 'medium', 'campaign', 'content', 'term'].forEach(function (key) {
			var value = params.get('utm_' + key);
			if (value) {
				utm[key] = value.slice(0, 191);
				found = true;
			}
		});
		if (found) {
			set(session, 'utm', JSON.stringify(utm));
		}
	})();

	function loadPanel() {
		if (panelPromise) {
			return panelPromise;
		}
		panelPromise = new Promise(function (resolve, reject) {
			if (win.KrivoChatFlowPanel) {
				resolve(win.KrivoChatFlowPanel);
				return;
			}
			var link = doc.createElement('link');
			link.rel = 'stylesheet';
			link.href = cfg.panel.css;
			doc.head.appendChild(link);
			var script = doc.createElement('script');
			script.src = cfg.panel.js;
			script.async = true;
			script.onload = function () {
				win.KrivoChatFlowPanel ? resolve(win.KrivoChatFlowPanel) : reject(new Error('panel'));
			};
			script.onerror = function () {
				panelPromise = null;
				reject(new Error('panel'));
			};
			doc.head.appendChild(script);
		});
		return panelPromise;
	}

	// ------------------------------------------------------------------ "button only" chats

	var buttonPromise = null;
	function loadButton() {
		if (buttonPromise) {
			return buttonPromise;
		}
		buttonPromise = new Promise(function (resolve, reject) {
			if (win.KrivoChatFlowButton) {
				resolve(win.KrivoChatFlowButton);
				return;
			}
			var script = doc.createElement('script');
			script.src = cfg.button.js;
			script.async = true;
			script.onload = function () {
				win.KrivoChatFlowButton ? resolve(win.KrivoChatFlowButton) : reject(new Error('button'));
			};
			script.onerror = function () {
				buttonPromise = null;
				reject(new Error('button'));
			};
			doc.head.appendChild(script);
		});
		return buttonPromise;
	}

	// What tapping a WhatsApp button does. The tab is opened inside the click (so no pop-up blocker objects);
	// button.js (downloaded only for chats that are just a button) asks the server for the wa.me link, because
	// the number is never printed in the page, and points the tab at it.
	function buttonAction(boot, notify) {
		var busy = false;
		return function () {
			if (cfg.preview) {
				notify(boot.i18n.preview_button);
				return;
			}
			if (busy) {
				return;
			}
			busy = true;
			var tab = null;
			try {
				tab = win.open('', '_blank');
			} catch (e) {
				tab = null;
			}
			loadButton()
				.then(function (api) {
					return api.go(cfg, boot, tab, notify, emit);
				})
				.catch(function () {
					if (tab) {
						tab.close();
					}
					notify(boot.i18n.button_error);
				})
				.then(function () {
					busy = false;
				});
		};
	}

	// Wires a button or link of a "button only" chat.
	function wireButton(el, boot, activate) {
		if (el.tagName === 'A') {
			el.addEventListener('click', function () {
				if (!cfg.preview) {
					ping(boot.id, ['click']);
				}
				emit('button', { flow: boot.id, mode: 'button' });
			});
		} else {
			el.addEventListener('click', activate);
		}
	}

	function idle(fn) {
		if (win.requestIdleCallback) {
			win.requestIdleCallback(fn, { timeout: 1500 });
		} else {
			win.setTimeout(fn, 1);
		}
	}

	// ------------------------------------------------------------------ bubble

	function setupBubble(boot, root) {
		var b = boot.bubble;
		applyBubbleStyle(root, b);

		var button = root.querySelector('.kcf-bubble');
		var tooltip = root.querySelector('.kcf-tooltip');
		var badge = root.querySelector('.kcf-badge');
		var instance = null;
		var shown = false;
		var isButton = !!boot.button;
		var tipText = tooltip && tooltip.querySelector('.kcf-tooltip-text');
		var tipOriginal = tipText ? tipText.textContent : '';

		function hideTooltip() {
			if (tooltip) {
				tooltip.hidden = true;
				tooltip.classList.remove('kcf-note');
				if (tipText) {
					tipText.textContent = tipOriginal;
				}
			}
		}

		// A message that replaces the welcome text for a moment (the link could not be opened, or the preview).
		function note(message) {
			if (!tooltip || !tipText) {
				return;
			}
			if (!message) {
				hideTooltip();
				return;
			}
			tooltip.classList.add('kcf-note');
			tipText.textContent = message;
			tooltip.hidden = false;
		}

		function showCard() {
			if (tooltip && b.tooltip.enabled) {
				tooltip.hidden = false;
			}
		}
		var activate = isButton ? buttonAction(boot, note) : null;

		function open(trigger) {
			hideTooltip();
			if (badge) {
				badge.hidden = true;
			}
			return loadPanel().then(function (panel) {
				if (!instance) {
					instance = panel.create({ cfg: cfg, boot: boot, root: root, bubble: button, emit: emit, preview: !!cfg.preview, manifest: cfg.manifest || null });
				}
				instance.open(trigger);
				return instance;
			});
		}

		function withInstance(method) {
			if (instance) {
				instance[method]();
			} else if (method !== 'close') {
				open('api');
			}
		}

		if (isButton) {
			wireButton(button, boot, activate);
			Array.prototype.forEach.call(root.querySelectorAll('.kcf-tooltip-cta'), function (el) {
				wireButton(el, boot, activate);
			});
		} else {
			button.addEventListener('click', function () {
				if (instance && instance.isOpen()) {
					instance.close();
				} else {
					open('click');
				}
			});
			// The welcome card can carry a button that does the same as the bubble.
			Array.prototype.forEach.call(root.querySelectorAll('.kcf-tooltip-cta'), function (el) {
				el.addEventListener('click', function () {
					open('click');
				});
			});
			['pointerenter', 'focus', 'touchstart', 'pointerdown'].forEach(function (name) {
				button.addEventListener(name, function () {
					loadPanel().catch(function () {});
				}, { passive: true, once: true });
			});
		}

		function reveal() {
			if (shown) {
				return;
			}
			shown = true;
			idle(function () {
				root.hidden = false;
				root.classList.add('kcf-in');
				if (isButton && !cfg.preview) {
					idle(function () {
						loadButton().catch(function () {});
					});
				}
				var beh = boot.behavior;
				if (tooltip && b.tooltip.enabled) {
					var seen = get(local, 'tip:' + boot.id + ':' + boot.rev);
					if (!(b.tooltip.once && seen)) {
						win.setTimeout(function () {
							if (!instance || !instance.isOpen()) {
								tooltip.hidden = false;
								if (b.tooltip.once) {
									set(local, 'tip:' + boot.id + ':' + boot.rev, '1');
								}
								if (b.tooltip.duration > 0) {
									win.setTimeout(hideTooltip, b.tooltip.duration * 1000);
								}
							}
						}, b.tooltip.delay * 1000);
					}
					var close = tooltip.querySelector('.kcf-tooltip-close');
					if (close) {
						close.addEventListener('click', hideTooltip);
					}
				}
				if (badge && b.badge.enabled) {
					win.setTimeout(function () {
						if (!instance || !instance.isOpen()) {
							badge.hidden = false;
						}
					}, b.badge.delay * 1000);
				}
				if (beh.auto_open && !(beh.auto_open_once && get(session, 'auto:' + boot.id))) {
					set(session, 'auto:' + boot.id, '1');
					idle(function () {
						// A "button only" chat has no window to open: "open automatically" shows its welcome card.
						isButton ? showCard() : open('auto');
					});
				}
				beacon(boot.id);
			});
		}

		var trigger = boot.behavior;
		if (trigger.trigger === 'scroll') {
			var onScroll = function () {
				var doc2 = doc.documentElement;
				var max = Math.max(1, doc2.scrollHeight - win.innerHeight);
				if ((win.pageYOffset / max) * 100 >= trigger.scroll_percent) {
					win.removeEventListener('scroll', onScroll);
					reveal();
				}
			};
			win.addEventListener('scroll', onScroll, { passive: true });
			onScroll();
		} else {
			win.setTimeout(reveal, Math.max(0, trigger.delay) * 1000);
		}

		return {
			open: function () {
				if (!shown) {
					root.hidden = false;
					root.classList.add('kcf-in');
				}
				if (isButton) {
					showCard();
					return Promise.resolve(null);
				}
				return open('api');
			},
			close: function () {
				isButton ? hideTooltip() : withInstance('close');
			},
			toggle: function () {
				if (isButton) {
					tooltip && tooltip.hidden ? showCard() : hideTooltip();
				} else if (instance && instance.isOpen()) {
					instance.close();
				} else {
					open('api');
				}
			},
		};
	}

	// Advisory counters (the server counts leads and WhatsApp clicks itself).
	function ping(flowId, events) {
		try {
			fetch(cfg.rest + 'public/events', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ flow: flowId, events: events }),
				keepalive: true,
				credentials: 'omit',
			}).catch(function () {});
		} catch (e) {
			/* advisory only */
		}
	}

	// One advisory "view" per session and flow.
	function beacon(flowId) {
		if (cfg.preview || get(session, 'view:' + flowId)) {
			return;
		}
		set(session, 'view:' + flowId, '1');
		ping(flowId, ['view']);
	}

	// ------------------------------------------------------------------ inline (shortcode)

	function setupInline() {
		// A "button only" chat placed by the shortcode: the button is already in the page, only wire it.
		Array.prototype.forEach.call(doc.querySelectorAll('[data-kcf-inline-button]'), function (host) {
			var boot = cfg.inline && cfg.inline[host.getAttribute('data-kcf-inline-button')];
			var el = host.querySelector('.kcf-inline-cta');
			if (!boot || !boot.button || !el) {
				return;
			}
			el.style.setProperty('--kcf-bg', boot.bubble.colors.bg);
			el.style.setProperty('--kcf-fg', boot.bubble.colors.icon);
			var message = doc.createElement('span');
			message.className = 'kcf-inline-note';
			message.setAttribute('role', 'status');
			host.appendChild(message);
			wireButton(el, boot, buttonAction(boot, function (text) {
				message.textContent = text;
			}));
		});
		var hosts = doc.querySelectorAll('[data-kcf-inline]');
		Array.prototype.forEach.call(hosts, function (host) {
			var boot = cfg.inline && cfg.inline[host.getAttribute('data-kcf-inline')];
			if (!boot) {
				return;
			}
			idle(function () {
				loadPanel().then(function (panel) {
					panel.create({ cfg: cfg, boot: boot, host: host, inline: true, emit: emit, preview: false }).open('inline');
				}).catch(function () {});
			});
		});
	}

	// ------------------------------------------------------------------ public API

	function run() {
		var root = doc.getElementById('krivo-chatflow-root');
		if (cfg.flow && root) {
			if (cfg.flow.devices.indexOf(device()) === -1) {
				root.parentNode.removeChild(root);
			} else {
				bubbleApi = setupBubble(cfg.flow, root);
			}
		}
		setupInline();
	}

	win.KrivoChatFlow = {
		version: cfg.version,
		open: function () {
			if (bubbleApi) {
				bubbleApi.open();
			}
		},
		close: function () {
			if (bubbleApi) {
				bubbleApi.close();
			}
		},
		toggle: function () {
			if (bubbleApi) {
				bubbleApi.toggle();
			}
		},
		on: function (name, fn) {
			var handler = function (event) {
				fn(event.detail);
			};
			doc.addEventListener(PREFIX + '_' + name, handler);
			return function () {
				doc.removeEventListener(PREFIX + '_' + name, handler);
			};
		},
	};

	try {
		run();
	} catch (e) {
		/* the widget must never break the page it sits on */
	}
})();
