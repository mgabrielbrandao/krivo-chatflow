/**
 * Applies a bubble's look through the CSS object model (custom properties on the root element), so
 * no style attribute or style tag is ever needed. Shared by the loader and by the admin preview.
 */
export function applyBubbleStyle(root, bubble) {
	const style = root.style;
	style.setProperty('--kcf-bg', bubble.colors.bg);
	style.setProperty('--kcf-fg', bubble.colors.icon);
	style.setProperty('--kcf-size', bubble.size + 'px');
	style.setProperty('--kcf-ox', bubble.offset_x + 'px');
	style.setProperty('--kcf-oy', bubble.offset_y + 'px');
	if (bubble.colors.border) {
		style.setProperty('--kcf-bc', bubble.colors.border);
		style.setProperty('--kcf-bw', '2px');
	} else {
		style.removeProperty('--kcf-bc');
		style.removeProperty('--kcf-bw');
	}
	const radius = { circle: '50%', squircle: '34%', pill: '999px', custom: bubble.radius + 'px' };
	style.setProperty('--kcf-radius', radius[bubble.shape] || '50%');
}
