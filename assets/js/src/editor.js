import { createHooks } from '@wordpress/hooks';
import domReady from '@wordpress/dom-ready';

window.a8csp_bgte = window.a8csp_bgte || {};
window.a8csp_bgte.hooks = createHooks();

domReady( () => {
	window.a8csp_bgte.hooks.doAction( 'editor.ready' );
} );
