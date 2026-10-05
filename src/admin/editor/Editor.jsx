import { useCallback, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { api, can } from '../api.js';
import { __, sprintf } from '../i18n.js';
import { Badge, Button, Notice, Spinner, Tabs, useApp, useLoad } from '../ui.jsx';
import { AutomationsTab } from './AutomationsTab.jsx';
import { BubbleTab } from './BubbleTab.jsx';
import { DesignTab } from './DesignTab.jsx';
import { EmailTab } from './EmailTab.jsx';
import { FlowTab } from './FlowTab.jsx';
import { LogicTab } from './LogicTab.jsx';
import { Preview } from './Preview.jsx';
import { PublishTab } from './PublishTab.jsx';
import { WhatsappTab } from './WhatsappTab.jsx';
import { tabFor } from './helpers.js';

const AUTOSAVE_MS = 1800;

export const EDITOR_TABS = ['flow', 'design', 'bubble', 'whatsapp', 'email', 'automations', 'logic', 'publish'];

/**
 * The chat editor. Everything is edited in a local copy and saved to the DRAFT automatically; the
 * live site only changes when the chat is published. The preview on the right is the real chat.
 */
export function Editor({ id, tab, onTab, onBack, onOpen }) {
	const { confirm, toast } = useApp();
	const meta = useLoad(() => api.get('meta'), []);
	const loaded = useLoad(() => api.get('flows/' + id), [id]);

	if (loaded.loading || meta.loading) {
		return <Spinner />;
	}
	if (loaded.error || meta.error) {
		const error = loaded.error || meta.error;
		return (
			<Notice tone="error" action={<Button onClick={onBack}>{__('Back to chats', 'krivo-chatflow')}</Button>}>
				{error.message}
			</Notice>
		);
	}
	return <EditorBody key={id} id={id} initial={loaded.data} meta={meta.data} tab={tab} onTab={onTab} onBack={onBack} onOpen={onOpen} confirm={confirm} toast={toast} />;
}

function EditorBody({ id, initial, meta, tab, onTab, onBack, onOpen, confirm, toast }) {
	const [flow, setFlow] = useState(initial);
	const [config, setConfig] = useState(initial.config);
	const [save, setSave] = useState({ state: 'saved', message: '' });
	const [busy, setBusy] = useState('');
	const savedJson = useRef(JSON.stringify(initial.config));
	const baseHash = useRef(initial.draft_hash);
	const inflight = useRef(false);
	const latest = useRef(config);
	latest.current = config;
	const timer = useRef(null);
	const failedJson = useRef('');

	const dirty = JSON.stringify(config) !== savedJson.current;

	const persist = useCallback(
		async ({ force = false } = {}) => {
			if (inflight.current) {
				return false;
			}
			const sending = latest.current;
			const sendingJson = JSON.stringify(sending);
			if (sendingJson === savedJson.current && !force) {
				return true;
			}
			inflight.current = true;
			setSave({ state: 'saving', message: '' });
			try {
				const result = await api.put('flows/' + id, { config: sending, base_hash: force ? '' : baseHash.current });
				baseHash.current = result.draft_hash;
				setFlow((current) => ({ ...current, ...result, config: undefined }));
				const serverJson = JSON.stringify(result.config);
				savedJson.current = serverJson;
				// The server normalises (ids, keys). If nothing changed meanwhile, show exactly what is stored.
				if (JSON.stringify(latest.current) === sendingJson && serverJson !== sendingJson) {
					setConfig(result.config);
				} else if (JSON.stringify(latest.current) === sendingJson) {
					savedJson.current = sendingJson;
				}
				setSave({ state: 'saved', message: '' });
				return true;
			} catch (error) {
				failedJson.current = sendingJson;
				if (error.status === 409) {
					setSave({ state: 'conflict', message: error.message });
				} else {
					setSave({ state: 'error', message: error.message });
				}
				return false;
			} finally {
				inflight.current = false;
			}
		},
		[id]
	);

	// Autosave: a pause in typing saves the draft.
	useEffect(() => {
		if (!dirty || save.state === 'conflict' || JSON.stringify(config) === failedJson.current) {
			return undefined;
		}
		timer.current = window.setTimeout(() => persist(), AUTOSAVE_MS);
		return () => window.clearTimeout(timer.current);
	}, [config, save.state, dirty]);

	// Leaving with unsaved edits asks first.
	useEffect(() => {
		const handler = (event) => {
			if (JSON.stringify(latest.current) !== savedJson.current || inflight.current) {
				event.preventDefault();
				event.returnValue = '';
			}
		};
		window.addEventListener('beforeunload', handler);
		return () => window.removeEventListener('beforeunload', handler);
	}, []);

	const flush = async () => {
		window.clearTimeout(timer.current);
		for (let i = 0; i < 20 && inflight.current; i += 1) {
			await new Promise((resolve) => window.setTimeout(resolve, 100));
		}
		return persist();
	};

	const adopt = (result) => {
		baseHash.current = result.draft_hash;
		savedJson.current = JSON.stringify(result.config);
		setFlow(result);
		setConfig(result.config);
		setSave({ state: 'saved', message: '' });
	};

	const reload = async () => {
		try {
			adopt(await api.get('flows/' + id));
		} catch (e) {
			toast('error', e.message);
		}
	};

	const overwrite = async () => {
		baseHash.current = '';
		setSave({ state: 'dirty', message: '' });
		await persist({ force: true });
	};

	const publish = async () => {
		setBusy('publish');
		try {
			if (!(await flush())) {
				return;
			}
			const result = await api.post('flows/' + id + '/publish');
			setFlow(result);
			toast('ok', __('Published. Your chat is live.', 'krivo-chatflow'));
		} catch (error) {
			if (error.data && error.data.report) {
				setFlow((current) => ({ ...current, report: error.data.report }));
				onTab('publish');
				toast('error', error.message);
			} else {
				toast('error', error.message);
			}
		} finally {
			setBusy('');
		}
	};

	const pause = async () => {
		setBusy('pause');
		try {
			await flush();
			setFlow(await api.post('flows/' + id + '/pause'));
			toast('ok', __('Paused. Visitors no longer see this chat.', 'krivo-chatflow'));
		} catch (error) {
			toast('error', error.message);
		} finally {
			setBusy('');
		}
	};

	const duplicate = async () => {
		setBusy('duplicate');
		try {
			await flush();
			const copy = await api.post('flows/' + id + '/duplicate');
			toast('ok', __('Copy created as a draft.', 'krivo-chatflow'));
			onOpen(copy.id);
		} catch (error) {
			toast('error', error.message);
		} finally {
			setBusy('');
		}
	};

	const remove = async () => {
		const sure = await confirm({
			title: __('Delete this chat?', 'krivo-chatflow'),
			message: __('The chat disappears from your site and its settings are removed. The leads it already collected are kept.', 'krivo-chatflow'),
			confirm: __('Delete chat', 'krivo-chatflow'),
			danger: true,
		});
		if (!sure) {
			return;
		}
		try {
			window.clearTimeout(timer.current);
			savedJson.current = JSON.stringify(latest.current);
			await api.del('flows/' + id);
			toast('ok', __('Chat deleted.', 'krivo-chatflow'));
			onBack();
		} catch (error) {
			toast('error', error.message);
		}
	};

	const update = useCallback((next) => setConfig(next), []);
	const variables = flow.variables;

	const report = flow.report || { errors: [], warnings: [] };
	const badges = useMemo(() => {
		const marks = {};
		report.errors.forEach((item) => (marks[tabFor(item)] = 'error'));
		report.warnings.forEach((item) => {
			const key = tabFor(item);
			marks[key] = marks[key] || 'warn';
		});
		return marks;
	}, [flow.report]);

	const labels = {
		flow: __('Questions', 'krivo-chatflow'),
		design: __('Design', 'krivo-chatflow'),
		bubble: __('Bubble', 'krivo-chatflow'),
		whatsapp: __('WhatsApp', 'krivo-chatflow'),
		email: __('E-mail', 'krivo-chatflow'),
		automations: __('Automations', 'krivo-chatflow'),
		logic: __('Logic', 'krivo-chatflow'),
		publish: __('Publish', 'krivo-chatflow'),
	};
	const tabs = EDITOR_TABS.map((key) => ({
		id: key,
		label: labels[key],
		badge: badges[key] ? 'warn' : '',
		badgeLabel: badges[key] === 'error' ? __('Needs attention', 'krivo-chatflow') : __('Worth a look', 'krivo-chatflow'),
	}));
	const current = EDITOR_TABS.includes(tab) ? tab : 'flow';
	const published = flow.status === 'published';
	const paused = flow.status === 'paused';

	const statusText = {
		saved: __('All changes saved', 'krivo-chatflow'),
		saving: __('Saving…', 'krivo-chatflow'),
		dirty: __('Unsaved changes', 'krivo-chatflow'),
		error: save.message ? sprintf(/* translators: %s: the reason the server gave. */ __('Could not save: %s', 'krivo-chatflow'), save.message) : __('Could not save', 'krivo-chatflow'),
		conflict: __('Someone else changed this chat', 'krivo-chatflow'),
	}[dirty && save.state === 'saved' ? 'dirty' : save.state];

	const editor = (
		<div className="kcf-editor">
			<div className="kcf-stack" style={{ minWidth: 0 }}>
				{save.state === 'conflict' ? (
					<Notice
						tone="error"
						action={
							<span className="kcf-row kcf-row--tight">
								<Button small onClick={reload}>
									{__('Load their version', 'krivo-chatflow')}
								</Button>
								<Button small kind="danger" onClick={overwrite}>
									{__('Keep mine', 'krivo-chatflow')}
								</Button>
							</span>
						}
					>
						{__('Another administrator saved changes to this chat while you were editing. Load their version (you lose your unsaved edits), or keep yours (you replace theirs).', 'krivo-chatflow')}
					</Notice>
				) : null}
				<Tabs tabs={tabs} value={current} onChange={onTab} label={__('Chat settings', 'krivo-chatflow')}>
					{current === 'flow' ? <FlowTab config={config} update={update} meta={meta} variables={variables} /> : null}
					{current === 'design' ? <DesignTab config={config} update={update} /> : null}
					{current === 'bubble' ? <BubbleTab config={config} update={update} meta={meta} /> : null}
					{current === 'whatsapp' ? <WhatsappTab flowId={id} config={config} update={update} meta={meta} variables={variables} /> : null}
					{current === 'email' ? <EmailTab flowId={id} config={config} update={update} meta={meta} variables={variables} /> : null}
					{current === 'automations' ? <AutomationsTab flowId={id} config={config} update={update} meta={meta} variables={variables} /> : null}
					{current === 'logic' ? <LogicTab config={config} update={update} meta={meta} /> : null}
					{current === 'publish' ? (
						<PublishTab flow={flow} config={config} update={update} meta={meta} onGoto={onTab} onPublish={publish} onPause={pause} onDuplicate={duplicate} onDelete={remove} busy={busy} canPublish={can('edit_flows')} canDelete={can('delete_flows')} canCreate={can('create_flows')} />
					) : null}
				</Tabs>
				<div className="kcf-sticky-save">
					<span className="kcf-small" role="status" aria-live="polite">
						{statusText}
					</span>
					<span className="kcf-row kcf-row--tight">
						<Button small onClick={() => flush()} disabled={!dirty || save.state === 'saving'} loading={save.state === 'saving'}>
							{__('Save now', 'krivo-chatflow')}
						</Button>
						<Button small kind="primary" onClick={publish} loading={busy === 'publish'} disabled={report.errors.length > 0}>
							{published ? __('Publish changes', 'krivo-chatflow') : __('Publish', 'krivo-chatflow')}
						</Button>
					</span>
				</div>
			</div>
			<Preview flowId={id} config={config} />
		</div>
	);

	return (
		<div className="kcf-stack">
			<div className="kcf-row kcf-row--tight" style={{ alignItems: 'center' }}>
				<Button small kind="ghost" onClick={onBack}>
					← {__('All chats', 'krivo-chatflow')}
				</Button>
				<strong style={{ fontSize: 18 }}>{config.name || __('Untitled chat', 'krivo-chatflow')}</strong>
				<Badge tone={published ? 'ok' : paused ? 'warn' : 'neutral'}>{published ? __('Published', 'krivo-chatflow') : paused ? __('Paused', 'krivo-chatflow') : __('Draft', 'krivo-chatflow')}</Badge>
				{published && flow.has_unpublished_changes ? <Badge tone="info">{__('Changes not live', 'krivo-chatflow')}</Badge> : null}
			</div>
			{editor}
		</div>
	);
}
