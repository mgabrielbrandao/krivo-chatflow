import { useEffect, useState } from '@wordpress/element';
import { api, can } from '../api.js';
import { ago } from '../format.js';
import { __, _n, sprintf } from '../i18n.js';
import { Badge, Button, Card, Empty, Modal, Notice, Spinner, TextField, Toggle, useApp, useLoad } from '../ui.jsx';
import { Editor } from '../editor/Editor.jsx';

/** "#/flow/12/design" -> { id: 12, tab: 'design' }; "#/new" -> { create: true }. */
export function parseHash(hash) {
	const flow = /^#\/flow\/(\d+)(?:\/([a-z]+))?$/.exec(hash || '');
	if (flow) {
		return { id: Number(flow[1]), tab: flow[2] || 'flow' };
	}
	return { create: hash === '#/new' };
}

export const go = (hash) => {
	window.location.hash = hash;
};

export function useHash() {
	const [hash, setHash] = useState(window.location.hash);
	useEffect(() => {
		const handler = () => setHash(window.location.hash);
		window.addEventListener('hashchange', handler);
		return () => window.removeEventListener('hashchange', handler);
	}, []);
	return hash;
}

const STATUS = {
	published: ['ok', () => __('Published', 'krivo-chatflow')],
	paused: ['warn', () => __('Paused', 'krivo-chatflow')],
	draft: ['neutral', () => __('Draft', 'krivo-chatflow')],
};

export function TemplatePicker({ templates, onPick, busy }) {
	const options = templates.concat([{ id: '', name: __('Blank', 'krivo-chatflow'), description: __('Start from nothing and add your own questions.', 'krivo-chatflow'), fields: [] }]);
	return (
		<div className="kcf-grid">
			{options.map((template) => (
				<button type="button" className="kcf-template" key={template.id || 'blank'} onClick={() => onPick(template)} disabled={!!busy}>
					<span className="kcf-template__name">{template.name}</span>
					<span className="kcf-muted">{template.description}</span>
					{template.fields.length ? <span className="kcf-small kcf-muted">{template.fields.join(' · ')}</span> : null}
				</button>
			))}
		</div>
	);
}

/** Choosing a template, a name and (optionally) WhatsApp creates a draft and opens it. */
export function CreateDialog({ templates, initial, onClose, onCreated }) {
	const { toast } = useApp();
	const [template, setTemplate] = useState(initial || null);
	const [name, setName] = useState('');
	const [whatsapp, setWhatsapp] = useState(true);
	const [busy, setBusy] = useState(false);

	const create = async (picked, nameValue, wa) => {
		setBusy(true);
		try {
			const body = {};
			if (picked.id) {
				body.template = picked.id;
				body.whatsapp = wa;
			}
			if (nameValue.trim()) {
				body.name = nameValue.trim();
			}
			const flow = await api.post('flows', body);
			onCreated(flow);
		} catch (error) {
			toast('error', error.message);
			setBusy(false);
		}
	};

	return (
		<Modal
			wide
			title={template ? sprintf(/* translators: %s: name of the template. */ __('New chat: %s', 'krivo-chatflow'), template.name) : __('Choose a starting point', 'krivo-chatflow')}
			onClose={onClose}
			footer={
				template ? (
					<>
						<Button onClick={() => setTemplate(null)}>{__('Back', 'krivo-chatflow')}</Button>
						<Button kind="primary" loading={busy} onClick={() => create(template, name, whatsapp)} data-autofocus>
							{__('Create chat', 'krivo-chatflow')}
						</Button>
					</>
				) : null
			}
		>
			{template ? (
				<div className="kcf-stack">
					<p className="kcf-muted">{template.description}</p>
					<TextField label={__('Name', 'krivo-chatflow')} help={__('Only you see this name.', 'krivo-chatflow')} value={name} onChange={setName} placeholder={template.name} maxLength={120} data-autofocus />
					{template.id ? <Toggle label={__('Continue on WhatsApp after the form', 'krivo-chatflow')} help={__('You can change this later.', 'krivo-chatflow')} checked={whatsapp} onChange={setWhatsapp} /> : null}
				</div>
			) : (
				<TemplatePicker templates={templates} onPick={setTemplate} busy={busy} />
			)}
		</Modal>
	);
}

function FlowsList({ onOpen }) {
	const { confirm, toast } = useApp();
	const list = useLoad(() => api.get('flows'), []);
	const templates = useLoad(() => (can('create_flows') ? api.get('templates') : Promise.resolve({ items: [] })), []);
	const [creating, setCreating] = useState(parseHash(window.location.hash).create);
	const [busy, setBusy] = useState(0);

	const act = async (flow, action) => {
		setBusy(flow.id);
		try {
			if (action === 'duplicate') {
				const copy = await api.post('flows/' + flow.id + '/duplicate');
				toast('ok', __('Copy created as a draft.', 'krivo-chatflow'));
				onOpen(copy.id);
				return;
			}
			if (action === 'pause') {
				await api.post('flows/' + flow.id + '/pause');
				toast('ok', __('Paused.', 'krivo-chatflow'));
			}
			if (action === 'delete') {
				const sure = await confirm({
					title: sprintf(/* translators: %s: name of the chat. */ __('Delete “%s”?', 'krivo-chatflow'), flow.title),
					message: __('The chat disappears from your site. The leads it already collected are kept.', 'krivo-chatflow'),
					confirm: __('Delete chat', 'krivo-chatflow'),
					danger: true,
				});
				if (!sure) {
					return;
				}
				await api.del('flows/' + flow.id);
				toast('ok', __('Chat deleted.', 'krivo-chatflow'));
			}
			list.reload();
		} catch (error) {
			toast('error', error.message);
		} finally {
			setBusy(0);
		}
	};

	if (list.loading && !list.data) {
		return <Spinner />;
	}
	if (list.error) {
		return <Notice tone="error">{list.error.message}</Notice>;
	}
	const items = list.data.items;

	return (
		<div className="kcf-stack">
			{items.length === 0 ? (
				<Card title={__('Create your first chat', 'krivo-chatflow')} subtitle={__('Pick a starting point. You can change everything afterwards, and nothing appears on your site until you publish.', 'krivo-chatflow')}>
					{can('create_flows') && templates.data ? (
						<TemplatePicker
							templates={templates.data.items}
							onPick={(template) => setCreating(template)}
						/>
					) : (
						<Empty title={__('No chats yet', 'krivo-chatflow')} />
					)}
				</Card>
			) : (
				<Card
					title={__('Your chats', 'krivo-chatflow')}
					actions={
						can('create_flows') ? (
							<Button kind="primary" onClick={() => setCreating(true)}>
								{__('New chat', 'krivo-chatflow')}
							</Button>
						) : null
					}
				>
					<div className="kcf-table-wrap">
						<table className="kcf-table">
							<thead>
								<tr>
									<th scope="col">{__('Name', 'krivo-chatflow')}</th>
									<th scope="col">{__('Status', 'krivo-chatflow')}</th>
									<th scope="col" className="is-num">
										{__('Leads', 'krivo-chatflow')}
									</th>
									<th scope="col">{__('Edited', 'krivo-chatflow')}</th>
									<th scope="col">
										<span className="kcf-sr">{__('Actions', 'krivo-chatflow')}</span>
									</th>
								</tr>
							</thead>
							<tbody>
								{items.map((flow) => {
									const [tone, label] = STATUS[flow.status] || STATUS.draft;
									return (
										<tr key={flow.id}>
											<td>
												<a className="kcf-link" href={'#/flow/' + flow.id} onClick={(event) => { event.preventDefault(); onOpen(flow.id); }}>
													<strong>{flow.title}</strong>
												</a>
											</td>
											<td>
												<Badge tone={tone}>{label()}</Badge>
											</td>
											<td className="is-num">{flow.leads}</td>
											<td>{ago(flow.updated_at)}</td>
											<td>
												<span className="kcf-row kcf-row--tight" style={{ justifyContent: 'flex-end' }}>
													<Button small onClick={() => onOpen(flow.id)}>
														{__('Edit', 'krivo-chatflow')}
													</Button>
													{flow.status === 'published' ? (
														<Button small kind="ghost" onClick={() => act(flow, 'pause')} loading={busy === flow.id}>
															{__('Pause', 'krivo-chatflow')}
														</Button>
													) : null}
													{can('create_flows') ? (
														<Button small kind="ghost" onClick={() => act(flow, 'duplicate')}>
															{__('Duplicate', 'krivo-chatflow')}
														</Button>
													) : null}
													{can('delete_flows') ? (
														<Button small kind="ghost" className="kcf-btn--danger-ghost" onClick={() => act(flow, 'delete')}>
															{__('Delete', 'krivo-chatflow')}
														</Button>
													) : null}
												</span>
											</td>
										</tr>
									);
								})}
							</tbody>
						</table>
					</div>
					<p className="kcf-muted kcf-small" style={{ marginTop: 12 }}>
						{sprintf(/* translators: %d: number of chats. */ _n('%d chat', '%d chats', items.length, 'krivo-chatflow'), items.length)}
					</p>
				</Card>
			)}
			{creating && templates.data ? (
				<CreateDialog
					templates={templates.data.items}
					initial={typeof creating === 'object' ? creating : null}
					onClose={() => {
						setCreating(false);
						if (window.location.hash === '#/new') {
							go('');
						}
					}}
					onCreated={(flow) => onOpen(flow.id)}
				/>
			) : null}
		</div>
	);
}

/** The ChatFlows screen: the list, or the editor of one chat. */
export function FlowsScreen() {
	const hash = useHash();
	const route = parseHash(hash);
	const open = (id, tab) => go('#/flow/' + id + (tab && tab !== 'flow' ? '/' + tab : ''));
	if (route.id) {
		return <Editor id={route.id} tab={route.tab} onTab={(tab) => open(route.id, tab)} onBack={() => go('')} onOpen={open} />;
	}
	return <FlowsList onOpen={open} />;
}
