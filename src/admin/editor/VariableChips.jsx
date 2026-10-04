import { __, sprintf } from '../i18n.js';
import { useApp } from '../ui.jsx';

/** Inserts text where the cursor is in the focused text box (React sees it as typing). */
export function insertAtCursor(text) {
	const el = document.activeElement;
	if (!el || !(el.tagName === 'TEXTAREA' || (el.tagName === 'INPUT' && el.type === 'text')) || !el.closest('.kcf-admin')) {
		return false;
	}
	const start = el.selectionStart === null ? el.value.length : el.selectionStart;
	const end = el.selectionEnd === null ? el.value.length : el.selectionEnd;
	const setter = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(el), 'value').set;
	setter.call(el, el.value.slice(0, start) + text + el.value.slice(end));
	el.selectionStart = el.selectionEnd = start + text.length;
	el.dispatchEvent(new Event('input', { bubbles: true }));
	return true;
}

/** The variables a message can use. Click to put one where the cursor is. */
export function VariableChips({ variables, only }) {
	const { toast } = useApp();
	const list = (variables || []).filter((variable) => !only || only.includes(variable.group));
	if (list.length === 0) {
		return null;
	}
	return (
		<div>
			<p className="kcf-field__help" style={{ marginBottom: 6 }}>
				{__('Click a variable to insert it where your cursor is:')}
			</p>
			<div className="kcf-chips">
				{list.map((variable) => (
					<button
						key={variable.name}
						type="button"
						className="kcf-chip"
						title={variable.label}
						onMouseDown={(event) => event.preventDefault()}
						onClick={async () => {
							const text = '{' + variable.name + '}';
							if (!insertAtCursor(text)) {
								try {
									await navigator.clipboard.writeText(text);
									toast('ok', sprintf(__('%s copied. Paste it in a message.'), text));
								} catch (e) {
									toast('info', text);
								}
							}
						}}
					>
						{'{' + variable.name + '}'}
					</button>
				))}
			</div>
		</div>
	);
}
