let counter = 0;

/** Unique suffix for element ids (several panels may live on one page). */
export function uid() {
	counter += 1;
	return 'kcf' + counter;
}

/**
 * Builds an element. Text is always inserted as text nodes (never as HTML), so nothing a visitor or
 * an administrator typed can become markup.
 */
export function h(tag, attrs, ...kids) {
	const el = document.createElement(tag);
	if (attrs) {
		Object.keys(attrs).forEach((name) => {
			const value = attrs[name];
			if (value === null || value === undefined || value === false) {
				return;
			}
			if (name === 'class') {
				el.className = value;
			} else if (name.slice(0, 2) === 'on') {
				el.addEventListener(name.slice(2), value);
			} else {
				el.setAttribute(name, value === true ? '' : String(value));
			}
		});
	}
	kids.forEach(function add(kid) {
		if (kid === null || kid === undefined || kid === false) {
			return;
		}
		if (Array.isArray(kid)) {
			kid.forEach(add);
		} else {
			el.appendChild(typeof kid === 'object' ? kid : document.createTextNode(String(kid)));
		}
	});
	return el;
}

export function clear(el) {
	while (el.firstChild) {
		el.removeChild(el.firstChild);
	}
}

const SVG_NS = 'http://www.w3.org/2000/svg';

/** A one-path SVG icon (drawn for this plugin; decorative, hidden from assistive technology). */
export function icon(d, stroke) {
	const svg = document.createElementNS(SVG_NS, 'svg');
	svg.setAttribute('viewBox', '0 0 24 24');
	svg.setAttribute('aria-hidden', 'true');
	svg.setAttribute('focusable', 'false');
	const path = document.createElementNS(SVG_NS, 'path');
	path.setAttribute('d', d);
	if (stroke) {
		path.setAttribute('fill', 'none');
		path.setAttribute('stroke', 'currentColor');
		path.setAttribute('stroke-width', '2.4');
		path.setAttribute('stroke-linecap', 'round');
		path.setAttribute('stroke-linejoin', 'round');
	} else {
		path.setAttribute('fill', 'currentColor');
	}
	svg.appendChild(path);
	return svg;
}

export const ICONS = {
	close: 'M6 6l12 12M18 6L6 18',
	send: 'M4 12h15M13 6l6 6-6 6',
	check: 'M5 12.5l4.5 4.5L19 7.5',
	back: 'M20 12H5M11 6l-6 6 6 6',
};

const ALLOWED = { A: ['href'], STRONG: [], B: [], EM: [], I: [], BR: [], P: [], UL: [], OL: [], LI: [] };

/**
 * Copies an administrator-written rich text block into the page using an allowlist of tags
 * (a, strong, em, br, p, ul, ol, li). The source is parsed in an inert document and rebuilt node by
 * node, so scripts, event handlers and unknown elements simply do not make it across.
 */
export function richInto(target, html) {
	const parsed = new DOMParser().parseFromString('<body>' + html + '</body>', 'text/html');
	(function copy(from, to) {
		Array.prototype.forEach.call(from.childNodes, (child) => {
			if (child.nodeType === 3) {
				to.appendChild(document.createTextNode(child.nodeValue));
			} else if (child.nodeType === 1 && Object.prototype.hasOwnProperty.call(ALLOWED, child.tagName)) {
				const el = document.createElement(child.tagName.toLowerCase());
				if (child.tagName === 'A') {
					const href = child.getAttribute('href') || '';
					if (/^(https?:\/\/|mailto:|tel:)/i.test(href)) {
						el.setAttribute('href', href);
						el.setAttribute('target', '_blank');
						el.setAttribute('rel', 'noopener noreferrer');
					}
				}
				copy(child, el);
				to.appendChild(el);
			} else if (child.nodeType === 1 && child.tagName !== 'SCRIPT' && child.tagName !== 'STYLE') {
				copy(child, to); // Unknown element: keep its text, drop the element.
			}
		});
	})(parsed.body, target);
}
