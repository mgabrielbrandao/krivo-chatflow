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
	dashboard: [() => __('Dashboard'), Dashboard],
	flows: [() => __('ChatFlows'), FlowsScreen],
	leads: [() => __('Leads'), LeadsScreen],
	routing: [() => __('Routing'), RoutingScreen],
	integrations: [() => __('Integrations'), IntegrationsScreen],
	analytics: [() => __('Analytics'), AnalyticsScreen],
	settings: [() => __('Settings'), SettingsScreen],
};

function App() {
	const [title, Screen] = SCREENS[config.screen] || SCREENS.dashboard;
	return (
		<Providers>
			<header className="kcf-head">
				<img className="kcf-head__logo" src={config.images.symbol} alt="" width="36" height="36" />
				<div className="kcf-head__titles">
					<div className="kcf-head__brand">Krivo ChatFlow</div>
					<div className="kcf-head__title" role="presentation">
						{title()}
					</div>
				</div>
			</header>
			<Screen />
		</Providers>
	);
}

const host = document.getElementById('krivo-chatflow-admin');
if (host) {
	host.replaceChildren();
	createRoot(host).render(<App />);
}
