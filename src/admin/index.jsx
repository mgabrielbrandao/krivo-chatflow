import { createRoot } from '@wordpress/element';
import { config } from './api.js';
import { __ } from './i18n.js';
import { Providers } from './ui.jsx';
import { AnalyticsScreen } from './screens/AnalyticsScreen.jsx';
import { Dashboard } from './screens/Dashboard.jsx';
import { FlowsScreen } from './screens/FlowsScreen.jsx';
import { IntegrationsScreen } from './screens/IntegrationsScreen.jsx';
import { LeadsScreen } from './screens/LeadsScreen.jsx';
import { RoutingScreen } from './screens/RoutingScreen.jsx';
import { SettingsScreen } from './screens/SettingsScreen.jsx';

const SCREENS = {
	dashboard: [() => __('Dashboard', 'krivo-chatflow'), Dashboard],
	flows: [() => __('ChatFlows', 'krivo-chatflow'), FlowsScreen],
	leads: [() => __('Leads', 'krivo-chatflow'), LeadsScreen],
	routing: [() => __('Routing', 'krivo-chatflow'), RoutingScreen],
	integrations: [() => __('Integrations', 'krivo-chatflow'), IntegrationsScreen],
	analytics: [() => __('Analytics', 'krivo-chatflow'), AnalyticsScreen],
	settings: [() => __('Settings', 'krivo-chatflow'), SettingsScreen],
};

function App() {
	const [title, Screen] = SCREENS[config.screen] || SCREENS.dashboard;
	return (
		<Providers>
			<div className="kcf-head">
				<img className="kcf-head__logo" src={config.images.symbol} alt="" width="36" height="36" />
				<div className="kcf-head__titles">
					<div className="kcf-head__brand">Krivo ChatFlow</div>
					<div className="kcf-head__title" role="presentation">
						{title()}
					</div>
				</div>
			</div>
			<Screen />
		</Providers>
	);
}

const host = document.getElementById('krivo-chatflow-admin');
if (host) {
	host.replaceChildren();
	createRoot(host).render(<App />);
}
