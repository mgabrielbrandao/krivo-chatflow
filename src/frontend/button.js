/**
 * Krivo ChatFlow: the WhatsApp button of a "button only" chat. Downloaded by the loader only for chats that
 * have no form (it is not part of the loader, so pages with a form-based chat never pay for it).
 *
 * Tapping the button asks the server for the wa.me link: the destination (which can depend on the page, the
 * campaign, the device and the business hours) is resolved there, the click is counted there, and the number
 * is never printed in the page. Nothing here talks to a third party; WhatsApp is opened by the visitor's browser.
 */
import { createApi } from './api.js';

(function () {
	'use strict';

	function device() {
		var w = window.innerWidth || document.documentElement.clientWidth;
		return w < 768 ? 'mobile' : w < 1025 ? 'tablet' : 'desktop';
	}

	// The campaign parameters the loader kept for this visit (session storage only, no cookies).
	function storedUtm() {
		try {
			return JSON.parse(window.sessionStorage.getItem('krivo_chatflow:utm') || '{}') || {};
		} catch (e) {
			return {};
		}
	}

	var api = null;

	window.KrivoChatFlowButton = {
		/**
		 * @param {Object} cfg    Page configuration (REST and admin-ajax addresses).
		 * @param {Object} boot   Boot data of the chat.
		 * @param {?Window} tab   Tab opened inside the click, or null when the browser refused.
		 * @param {Function} notify Shows a short message in the welcome card ('' hides it).
		 * @param {Function} emit   Publishes a ChatFlow event.
		 * @return {Promise<void>}
		 */
		go: function (cfg, boot, tab, notify, emit) {
			api = api || createApi(cfg);
			var context = { url: window.location.href, title: document.title, referrer: document.referrer, device: device(), utm: storedUtm() };
			return api.button(boot.id, context).then(function (res) {
				var url = res && res.data && res.data.url;
				if (res && res.status === 200 && typeof url === 'string' && url.indexOf('https://wa.me/') === 0) {
					notify('');
					if (tab) {
						try {
							tab.opener = null;
						} catch (e) {
							/* a proxy may refuse; the link still opens */
						}
						tab.location.href = url;
					} else {
						window.location.href = url;
					}
					emit('whatsapp', { flowId: boot.id, rev: boot.rev, mode: 'button' });
					return;
				}
				if (tab) {
					tab.close();
				}
				notify((res && res.data && res.data.message) || boot.i18n.button_error);
			});
		},
	};
})();
