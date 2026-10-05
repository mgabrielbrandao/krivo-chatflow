// Activation, deactivation, reactivation and uninstall on a clean WordPress site, with WP_DEBUG on.
// Run by hand (it removes the plugin at the end, then installs it again from the ZIP):
//
//   KRIVO_E2E_URL=… KRIVO_E2E_WP=… KRIVO_E2E_ZIP=build/krivo-chatflow-0.1.0.zip node tests/e2e/support/lifecycle.mjs
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { BASE, launch, login, restClient, wp } from '../helpers.mjs';

const ZIP = process.env.KRIVO_E2E_ZIP;
assert.ok(ZIP && existsSync(ZIP), 'Set KRIVO_E2E_ZIP to the plugin ZIP');
const log = wp('eval', 'echo WP_CONTENT_DIR;').trim() + '/debug.log';
const ours = () => (existsSync(log) ? readFileSync(log, 'utf8').split('\n').filter((l) => /krivo/i.test(l) && !/wp_(version|update)_/.test(l)) : []);
const tables = () => wp('db', 'query', "SHOW TABLES LIKE '%krivo_chatflow%'").split('\n').filter((l) => /krivo_chatflow/.test(l) && !/^Tables_in/.test(l));
const prefix = wp('eval', 'global $wpdb; echo $wpdb->prefix;').trim();
const options = () => wp('db', 'query', `SELECT option_name FROM ${prefix}options WHERE option_name LIKE 'krivo_chatflow%'`).split('\n').filter((l) => /^krivo_chatflow/.test(l));
const step = (name) => console.log('•', name);
const home = async () => (await fetch(BASE + '/')).status;

const browser = await launch();
const context = await browser.newContext();
await login(context);
const rest = await restClient(context);

step('plugin is active, tables and capabilities exist');
assert.equal(tables().length, 4);
assert.equal(await home(), 200);

step('create and publish a chat, so there is something to lose');
const flow = (await rest.post('flows', { template: 'contact', name: 'Lifecycle', whatsapp: false })).body;
assert.equal((await rest.post('flows/' + flow.id + '/publish')).status, 200);
const lead = await context.request.post(BASE + '/?rest_route=/krivo-chatflow/v1/public/session', { headers: { Origin: BASE, 'Content-Type': 'application/json' }, data: JSON.stringify({ flow: flow.id }) });
assert.equal(lead.status(), 200);

step('deactivate: the site keeps working, the data stays');
wp('plugin', 'deactivate', 'krivo-chatflow');
assert.equal(await home(), 200);
assert.equal(tables().length, 4, 'deactivating deletes nothing');
assert.equal(wp('cron', 'event', 'list', '--format=csv').split('\n').filter((l) => /krivo_chatflow/.test(l)).length, 0, 'the plugin’s scheduled tasks are removed');

step('reactivate: everything is back');
wp('plugin', 'activate', 'krivo-chatflow');
assert.equal(await home(), 200);
assert.equal((await rest.get('flows/' + flow.id)).status, 200, 'the chat survived');

step('uninstall WITHOUT the opt-in: nothing is deleted');
wp('plugin', 'deactivate', 'krivo-chatflow');
wp('plugin', 'uninstall', 'krivo-chatflow');
assert.equal(tables().length, 4, 'tables are kept when the administrator did not ask to delete data');
assert.ok(options().length > 0, 'and so are the settings');
wp('plugin', 'install', ZIP, '--force');
wp('plugin', 'activate', 'krivo-chatflow');

step('uninstall WITH the opt-in: tables and options are removed');
wp('eval', '\\Krivo\\ChatFlow\\Core\\Settings\\Settings::update( array( "privacy" => array( "delete_on_uninstall" => true ) ) );');
wp('plugin', 'deactivate', 'krivo-chatflow');
wp('plugin', 'uninstall', 'krivo-chatflow');
assert.equal(tables().length, 0, 'every table is dropped');
assert.deepEqual(options(), [], 'every option of the plugin is deleted');

step('install again from the ZIP and activate (fresh start)');
wp('plugin', 'install', ZIP, '--activate');
assert.equal(tables().length, 4);
assert.equal(await home(), 200);

assert.deepEqual(ours(), [], 'no PHP notice, warning or deprecation from the plugin in debug.log');
await browser.close();
console.log('lifecycle OK');
