import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

export const boot = window.aiActLabelsAdmin || {};

/**
 * A route of `ai-act-labels/v1` (includes/class-rest.php).
 * @param path
 * @param options
 */
export const api = ( path, options = {} ) =>
	apiFetch( { path: `/ai-act-labels/v1${ path }`, ...options } );

export const post = ( path, data = {} ) =>
	api( path, { method: 'POST', data } );

/**
 * What went wrong, as one line.
 * @param error
 */
export function problem( error ) {
	return {
		code: String( error?.code || 'failed' ).replace(
			/^ai_act_labels_/,
			''
		),
		message:
			error?.message || __( 'That did not work.', 'ai-act-image-labels' ),
	};
}

/**
 * `?a=b` of the set values.
 * @param params
 */
export function query( params ) {
	const q = new URLSearchParams();
	Object.entries( params ).forEach( ( [ key, value ] ) => {
		if ( value !== undefined && value !== null && value !== '' ) {
			q.set( key, String( value ) );
		}
	} );
	const s = q.toString();
	return s ? `?${ s }` : '';
}
