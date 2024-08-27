import { createHooks } from '@wordpress/hooks';
import domReady from '@wordpress/dom-ready';

window.a8csp_background_tasks = window.a8csp_background_tasks || {};
window.a8csp_background_tasks.hooks = createHooks();

domReady( () => {
	window.a8csp_background_tasks.hooks.doAction( 'editor.ready' );
} );
