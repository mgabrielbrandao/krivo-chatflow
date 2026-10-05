import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from './i18n.js';

let counter = 0;
/** A stable unique id for associating labels, help text and errors with their control. */
export function useUid(prefix = 'kcf') {
	const ref = useRef(null);
	if (ref.current === null) {
		counter += 1;
		ref.current = prefix + '-' + counter;
	}
	return ref.current;
}

const cx = (...names) => names.filter(Boolean).join(' ');

// ---------------------------------------------------------------------------------------- basics

export function Button({ kind = 'secondary', small, loading, children, className, ...rest }) {
	return (
		<button type="button" className={cx('kcf-btn', 'kcf-btn--' + kind, small && 'kcf-btn--small', className)} disabled={rest.disabled || loading} aria-busy={loading ? 'true' : undefined} {...rest}>
			{loading ? <span className="kcf-spin" aria-hidden="true" /> : null}
			{children}
		</button>
	);
}

export function LinkButton({ href, children, kind = 'secondary', small, external, ...rest }) {
	return (
		<a className={cx('kcf-btn', 'kcf-btn--' + kind, small && 'kcf-btn--small')} href={href} {...(external ? { target: '_blank', rel: 'noopener noreferrer' } : {})} {...rest}>
			{children}
		</a>
	);
}

export function Badge({ tone = 'neutral', children }) {
	return <span className={'kcf-badge kcf-badge--' + tone}>{children}</span>;
}

export function Card({ title, subtitle, actions, children, className, id }) {
	return (
		<section className={cx('kcf-card', className)} id={id} aria-labelledby={title ? id + '-t' : undefined}>
			{title || actions ? (
				<header className="kcf-card__head">
					<div>
						{title ? (
							<h2 className="kcf-card__title" id={id ? id + '-t' : undefined}>
								{title}
							</h2>
						) : null}
						{subtitle ? <p className="kcf-card__sub">{subtitle}</p> : null}
					</div>
					{actions ? <div className="kcf-card__actions">{actions}</div> : null}
				</header>
			) : null}
			<div className="kcf-card__body">{children}</div>
		</section>
	);
}

export function Spinner({ label }) {
	return (
		<div className="kcf-loading" role="status">
			<span className="kcf-spin kcf-spin--big" aria-hidden="true" />
			<span>{label || __('Loading…', 'krivo-chatflow')}</span>
		</div>
	);
}

export function Empty({ title, children, action }) {
	return (
		<div className="kcf-empty">
			<svg className="kcf-empty__art" width="132" height="84" viewBox="0 0 132 84" fill="none" aria-hidden="true" focusable="false">
				<rect x="6.5" y="14.5" width="119" height="55" rx="27.5" stroke="#A14DFF" strokeOpacity=".4" />
				<rect x="24.5" y="25.5" width="83" height="33" rx="16.5" stroke="#7B2FF7" strokeOpacity=".55" />
				<circle cx="66" cy="42" r="7" fill="#7B2FF7" />
				<path d="M2 42h7M123 42h7" stroke="#5521B5" strokeOpacity=".45" strokeLinecap="round" />
			</svg>
			<p className="kcf-empty__title">{title}</p>
			{children ? <p className="kcf-empty__text">{children}</p> : null}
			{action}
		</div>
	);
}

export function Notice({ tone = 'info', children, onDismiss, action }) {
	return (
		<div className={'kcf-notice kcf-notice--' + tone} role={tone === 'error' ? 'alert' : 'status'}>
			<div className="kcf-notice__body">{children}</div>
			{action}
			{onDismiss ? (
				<button type="button" className="kcf-notice__x" onClick={onDismiss} aria-label={__('Dismiss', 'krivo-chatflow')}>
					<Icon name="x" size={16} />
				</button>
			) : null}
		</div>
	);
}

// ---------------------------------------------------------------------------------------- form fields

function FieldShell({ id, label, help, error, children, inline, optional }) {
	return (
		<div className={cx('kcf-field', inline && 'kcf-field--inline', error && 'kcf-field--error')}>
			{label ? (
				<label className="kcf-field__label" htmlFor={id}>
					{label}
					{optional ? <span className="kcf-field__opt"> {__('(optional)', 'krivo-chatflow')}</span> : null}
				</label>
			) : null}
			{children}
			{help ? (
				<p className="kcf-field__help" id={id + '-h'}>
					{help}
				</p>
			) : null}
			{error ? (
				<p className="kcf-field__error" id={id + '-e'} role="alert">
					{error}
				</p>
			) : null}
		</div>
	);
}

const describe = (id, help, error) => [help ? id + '-h' : '', error ? id + '-e' : ''].filter(Boolean).join(' ') || undefined;

const ICONS = {
	x: 'M6 6l12 12M18 6L6 18',
	check: 'M5 12.5l4.5 4.5L19 7.5',
	'arrow-up': 'M12 19V5M6 11l6-6 6 6',
	'arrow-down': 'M12 5v14M6 13l6 6 6-6',
	'arrow-left': 'M19 12H5M11 6l-6 6 6 6',
	copy: 'M9.5 9h8a1.5 1.5 0 011.5 1.5v8a1.5 1.5 0 01-1.5 1.5h-8A1.5 1.5 0 018 18.5v-8A1.5 1.5 0 019.5 9zM5 15.5v-9A1.5 1.5 0 016.5 5h9',
};

/** Linear, geometric icons (one stroke width, round ends): the whole admin uses these instead of text glyphs. */
export function Icon({ name, size = 18 }) {
	const props = { className: 'kcf-ico', width: size, height: size, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 1.75, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': 'true', focusable: 'false' };
	if (name === 'grip') {
		return (
			<svg {...props}>
				{[6, 12, 18].map((y) => [9, 15].map((x) => <circle key={x + '-' + y} cx={x} cy={y} r="1.15" fill="currentColor" stroke="none" />))}
			</svg>
		);
	}
	return (
		<svg {...props}>
			<path d={ICONS[name]} />
		</svg>
	);
}

export function TextField({ label, help, error, value, onChange, type = 'text', optional, className, ...rest }) {
	const id = useUid();
	return (
		<FieldShell id={id} label={label} help={help} error={error} optional={optional}>
			<input id={id} className={cx('kcf-input', className)} type={type} value={value === null || value === undefined ? '' : value} onChange={(event) => onChange(event.target.value)} aria-invalid={error ? 'true' : undefined} aria-describedby={describe(id, help, error)} {...rest} />
		</FieldShell>
	);
}

export function TextArea({ label, help, error, value, onChange, rows = 4, optional, ...rest }) {
	const id = useUid();
	return (
		<FieldShell id={id} label={label} help={help} error={error} optional={optional}>
			<textarea id={id} className="kcf-input kcf-textarea" rows={rows} value={value || ''} onChange={(event) => onChange(event.target.value)} aria-invalid={error ? 'true' : undefined} aria-describedby={describe(id, help, error)} {...rest} />
		</FieldShell>
	);
}

export function SelectField({ label, help, error, value, onChange, options, optional, ...rest }) {
	const id = useUid();
	return (
		<FieldShell id={id} label={label} help={help} error={error} optional={optional}>
			<select id={id} className="kcf-input kcf-select" value={value === null || value === undefined ? '' : value} onChange={(event) => onChange(event.target.value)} aria-invalid={error ? 'true' : undefined} aria-describedby={describe(id, help, error)} {...rest}>
				{options.map((option) => (
					<option key={option.value} value={option.value}>
						{option.label}
					</option>
				))}
			</select>
		</FieldShell>
	);
}

export function NumberField({ label, help, error, value, onChange, min, max, step = 1, optional, suffix, ...rest }) {
	const id = useUid();
	return (
		<FieldShell id={id} label={label} help={help} error={error} optional={optional}>
			<div className="kcf-suffix">
				<input id={id} className="kcf-input" type="number" inputMode="numeric" min={min} max={max} step={step} value={value === null || value === undefined ? '' : value} onChange={(event) => onChange(event.target.value === '' ? '' : Number(event.target.value))} aria-describedby={describe(id, help, error)} {...rest} />
				{suffix ? <span className="kcf-suffix__text">{suffix}</span> : null}
			</div>
		</FieldShell>
	);
}

/** An on/off switch (role="switch"), operable with the keyboard like a checkbox. */
export function Toggle({ label, help, checked, onChange, disabled }) {
	const id = useUid();
	return (
		<div className="kcf-toggle">
			<input id={id} className="kcf-toggle__input" type="checkbox" role="switch" checked={!!checked} disabled={disabled} onChange={(event) => onChange(event.target.checked)} aria-describedby={help ? id + '-h' : undefined} />
			<label htmlFor={id} className="kcf-toggle__label">
				<span className="kcf-toggle__track" aria-hidden="true">
					<span className="kcf-toggle__thumb" />
				</span>
				<span>
					<span className="kcf-toggle__text">{label}</span>
					{help ? (
						<span className="kcf-toggle__help" id={id + '-h'}>
							{help}
						</span>
					) : null}
				</span>
			</label>
		</div>
	);
}

export function CheckField({ label, checked, onChange, help }) {
	const id = useUid();
	return (
		<div className="kcf-check">
			<input id={id} type="checkbox" checked={!!checked} onChange={(event) => onChange(event.target.checked)} aria-describedby={help ? id + '-h' : undefined} />
			<label htmlFor={id}>
				{label}
				{help ? (
					<span className="kcf-check__help" id={id + '-h'}>
						{help}
					</span>
				) : null}
			</label>
		</div>
	);
}

const HEX = /^#[0-9a-f]{6}$/i;

/** A colour picker plus a hex text box; only valid #RRGGBB values are passed on. */
export function ColorField({ label, help, value, onChange, optional }) {
	const id = useUid();
	const [text, setText] = useState(value || '');
	useEffect(() => setText(value || ''), [value]);
	return (
		<FieldShell id={id} label={label} help={help} optional={optional}>
			<div className="kcf-color">
				<input type="color" className="kcf-color__swatch" value={HEX.test(value || '') ? value : '#000000'} onChange={(event) => onChange(event.target.value.toUpperCase())} aria-label={label + ' — ' + __('colour picker', 'krivo-chatflow')} />
				<input
					id={id}
					className="kcf-input kcf-color__hex"
					type="text"
					value={text}
					maxLength={7}
					spellCheck="false"
					onChange={(event) => {
						setText(event.target.value);
						if (HEX.test(event.target.value)) {
							onChange(event.target.value.toUpperCase());
						}
						if (event.target.value === '' && optional) {
							onChange('');
						}
					}}
					aria-describedby={help ? id + '-h' : undefined}
				/>
			</div>
		</FieldShell>
	);
}

/** A row of mutually exclusive choices (radio group) shown as buttons. */
export function Segmented({ label, value, onChange, options, help }) {
	const id = useUid();
	return (
		<fieldset className="kcf-field kcf-segmented">
			<legend className="kcf-field__label">{label}</legend>
			<div className="kcf-segmented__row" role="radiogroup" aria-describedby={help ? id + '-h' : undefined}>
				{options.map((option) => (
					<label key={option.value} className={cx('kcf-segmented__item', value === option.value && 'is-on')}>
						<input type="radio" name={id} value={option.value} checked={value === option.value} onChange={() => onChange(option.value)} />
						<span>{option.label}</span>
					</label>
				))}
			</div>
			{help ? (
				<p className="kcf-field__help" id={id + '-h'}>
					{help}
				</p>
			) : null}
		</fieldset>
	);
}

// ---------------------------------------------------------------------------------------- tabs

/** Accessible tabs: arrow keys move between them, Home/End jump, the panel is labelled by its tab. */
export function Tabs({ tabs, value, onChange, children, label }) {
	const refs = useRef({});
	const move = (index) => {
		const tab = tabs[(index + tabs.length) % tabs.length];
		onChange(tab.id);
		window.requestAnimationFrame(() => refs.current[tab.id] && refs.current[tab.id].focus());
	};
	return (
		<div className="kcf-tabs">
			<div className="kcf-tabs__list" role="tablist" aria-label={label}>
				{tabs.map((tab, index) => (
					<button
						key={tab.id}
						ref={(node) => (refs.current[tab.id] = node)}
						type="button"
						role="tab"
						id={'kcf-tab-' + tab.id}
						className={cx('kcf-tabs__tab', value === tab.id && 'is-on')}
						aria-selected={value === tab.id}
						aria-controls={'kcf-panel-' + tab.id}
						tabIndex={value === tab.id ? 0 : -1}
						onClick={() => onChange(tab.id)}
						onKeyDown={(event) => {
							if (event.key === 'ArrowRight') {
								event.preventDefault();
								move(index + 1);
							} else if (event.key === 'ArrowLeft') {
								event.preventDefault();
								move(index - 1);
							} else if (event.key === 'Home') {
								event.preventDefault();
								move(0);
							} else if (event.key === 'End') {
								event.preventDefault();
								move(tabs.length - 1);
							}
						}}
					>
						{tab.label}
						{tab.badge ? <span className={'kcf-tabs__badge kcf-tabs__badge--' + tab.badge} aria-label={tab.badgeLabel} /> : null}
					</button>
				))}
			</div>
			<div className="kcf-tabs__panel" role="tabpanel" id={'kcf-panel-' + value} aria-labelledby={'kcf-tab-' + value} tabIndex={0}>
				{children}
			</div>
		</div>
	);
}

// ---------------------------------------------------------------------------------------- modal

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/** A modal dialog: focus moves in, Tab stays inside, Esc closes, focus returns to the opener. */
export function Modal({ title, onClose, children, footer, wide }) {
	const ref = useRef(null);
	const id = useUid('kcf-modal');
	useEffect(() => {
		const previous = document.activeElement;
		const node = ref.current;
		const first = node.querySelector('[data-autofocus]') || node.querySelector(FOCUSABLE) || node;
		first.focus();
		const onKey = (event) => {
			if (event.key === 'Escape') {
				event.stopPropagation();
				onClose();
			} else if (event.key === 'Tab') {
				const items = Array.from(node.querySelectorAll(FOCUSABLE));
				if (items.length === 0) {
					return;
				}
				const firstItem = items[0];
				const lastItem = items[items.length - 1];
				if (event.shiftKey && document.activeElement === firstItem) {
					event.preventDefault();
					lastItem.focus();
				} else if (!event.shiftKey && document.activeElement === lastItem) {
					event.preventDefault();
					firstItem.focus();
				}
			}
		};
		document.addEventListener('keydown', onKey, true);
		return () => {
			document.removeEventListener('keydown', onKey, true);
			if (previous && previous.focus) {
				previous.focus();
			}
		};
	}, []);
	return (
		<div className="kcf-modal" role="presentation" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
			<div className={cx('kcf-modal__box', wide && 'kcf-modal__box--wide')} role="dialog" aria-modal="true" aria-labelledby={id} ref={ref} tabIndex={-1}>
				<div className="kcf-modal__head">
					<h2 id={id}>{title}</h2>
					<button type="button" className="kcf-modal__x" onClick={onClose} aria-label={__('Close', 'krivo-chatflow')}>
						<Icon name="x" size={20} />
					</button>
				</div>
				<div className="kcf-modal__body">{children}</div>
				{footer ? <div className="kcf-modal__foot">{footer}</div> : null}
			</div>
		</div>
	);
}

const AppContext = createContext(null);

/** Provides `confirm()` (a promise-returning dialog) and `toast()` (a polite live message) to the app. */
export function Providers({ children }) {
	const [dialog, setDialog] = useState(null);
	const [toasts, setToasts] = useState([]);

	const confirm = useCallback(
		(options) =>
			new Promise((resolve) => {
				setDialog({ ...options, resolve });
			}),
		[]
	);
	const close = (answer) => {
		dialog.resolve(answer);
		setDialog(null);
	};
	const toast = useCallback((tone, text) => {
		const id = Date.now() + Math.random();
		setToasts((list) => list.concat({ id, tone, text }));
		window.setTimeout(() => setToasts((list) => list.filter((item) => item.id !== id)), tone === 'error' ? 9000 : 4500);
	}, []);
	const value = useMemo(() => ({ confirm, toast }), [confirm, toast]);

	return (
		<AppContext.Provider value={value}>
			{children}
			{dialog ? (
				<Modal
					title={dialog.title}
					onClose={() => close(false)}
					footer={
						<>
							<Button onClick={() => close(false)}>{dialog.cancel || __('Cancel', 'krivo-chatflow')}</Button>
							<Button kind={dialog.danger ? 'danger' : 'primary'} onClick={() => close(true)} data-autofocus>
								{dialog.confirm || __('Confirm', 'krivo-chatflow')}
							</Button>
						</>
					}
				>
					<p>{dialog.message}</p>
				</Modal>
			) : null}
			<div className="kcf-toasts" aria-live="polite" aria-atomic="false">
				{toasts.map((item) => (
					<div key={item.id} className={'kcf-toast kcf-toast--' + item.tone} role={item.tone === 'error' ? 'alert' : 'status'}>
						{item.text}
					</div>
				))}
			</div>
		</AppContext.Provider>
	);
}

export const useApp = () => useContext(AppContext);

// ---------------------------------------------------------------------------------------- misc

export function CopyButton({ text, label }) {
	const [done, setDone] = useState(false);
	return (
		<Button
			small
			onClick={async () => {
				try {
					await navigator.clipboard.writeText(text);
				} catch (e) {
					const area = document.createElement('textarea');
					area.value = text;
					document.body.appendChild(area);
					area.select();
					document.execCommand('copy');
					document.body.removeChild(area);
				}
				setDone(true);
				window.setTimeout(() => setDone(false), 1800);
			}}
		>
			{done ? __('Copied', 'krivo-chatflow') : label || __('Copy', 'krivo-chatflow')}
		</Button>
	);
}

export function Pagination({ page, perPage, total, onPage }) {
	const pages = Math.max(1, Math.ceil(total / perPage));
	if (pages <= 1) {
		return null;
	}
	return (
		<nav className="kcf-pager" aria-label={__('Pagination', 'krivo-chatflow')}>
			<Button small disabled={page <= 1} onClick={() => onPage(page - 1)}>
				{__('Previous', 'krivo-chatflow')}
			</Button>
			<span className="kcf-pager__info">
				{page} / {pages}
			</span>
			<Button small disabled={page >= pages} onClick={() => onPage(page + 1)}>
				{__('Next', 'krivo-chatflow')}
			</Button>
		</nav>
	);
}

/** Runs an async loader and exposes { data, error, loading, reload }. */
export function useLoad(loader, deps) {
	const [state, setState] = useState({ data: null, error: null, loading: true });
	const run = useCallback(() => {
		let live = true;
		setState((current) => ({ ...current, loading: true, error: null }));
		loader().then(
			(data) => live && setState({ data, error: null, loading: false }),
			(error) => live && setState({ data: null, error, loading: false })
		);
		return () => {
			live = false;
		};
	}, deps);
	useEffect(run, [run]);
	return { ...state, reload: run };
}

export { cx };
