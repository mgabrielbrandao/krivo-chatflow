// WordPress translates the admin app (wp_set_script_translations). The functions come from wp.i18n and
// every call passes the text domain explicitly, so WordPress's own tools (and translate.wordpress.org) can
// find the strings in the source.
import { __, _n, sprintf } from '@wordpress/i18n';

export { __, _n, sprintf };
