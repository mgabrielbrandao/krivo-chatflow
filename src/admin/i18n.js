// WordPress translates the admin app (wp_set_script_translations): __ and friends come from wp.i18n.
import * as i18n from '@wordpress/i18n';

export const __ = (text) => i18n.__(text, 'krivo-chatflow');
export const _n = (single, plural, number) => i18n._n(single, plural, number, 'krivo-chatflow');
export const sprintf = i18n.sprintf;
