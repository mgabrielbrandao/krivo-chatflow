import { useEffect, useRef } from '@wordpress/element';

/** Shows an SVG string that PHP produced (the built-in icons), parsed as DOM rather than injected as HTML. */
export function SvgIcon({ markup, size = 24 }) {
	const ref = useRef(null);
	useEffect(() => {
		const host = ref.current;
		host.replaceChildren();
		if (!markup) {
			return;
		}
		const doc = new DOMParser().parseFromString(markup, 'image/svg+xml');
		const svg = doc.documentElement;
		if (svg && svg.nodeName.toLowerCase() === 'svg') {
			host.appendChild(document.importNode(svg, true));
		}
	}, [markup]);
	return <span ref={ref} aria-hidden="true" style={{ display: 'inline-block', width: size, height: size, lineHeight: 0 }} />;
}
