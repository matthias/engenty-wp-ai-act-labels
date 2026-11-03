/**
 * The media library's additions: the icon on labelled tiles (and a dashed one where the file suggests
 * a label), the filter „KI“ in the toolbar of the grid and the modal, and the way to the tool.
 * The fields themselves come from PHP (attachment_fields_to_edit) and save as WordPress saves them.
 */
import apiFetch from '@wordpress/api-fetch';
import { sprintf, __ } from '@wordpress/i18n';
import './media.css';

const data = window.aiActLabelsMedia || {};
const media = window.wp && window.wp.media;

/**
 * The icon on one tile.
 * @param view An attachment view.
 */
function decorate( view ) {
	const el = view.el;
	const old = el.querySelector( '.ai-act-tile' );
	if ( old ) {
		old.remove();
	}
	const ai = view.model.get( 'aiAct' );
	const preview = el.querySelector( '.attachment-preview' );
	if ( ! ai || ! preview ) {
		return;
	}
	const tile = document.createElement( 'span' );
	if ( ai.state === 'generated' || ai.state === 'modified' ) {
		tile.className = `ai-act-tile is-${ ai.state }`;
		tile.title = data.states?.[ ai.state ] || '';
	} else if ( ! ai.state && ai.suggests && ai.suggests !== 'none' ) {
		tile.className = 'ai-act-tile is-suggest';
		tile.title = sprintf(
			/* translators: %s: KI-generiert or Mit KI verändert */
			__( 'Suggestion: %s', 'ai-act-image-labels' ),
			data.states?.[ ai.suggests ] || ''
		);
	} else {
		return;
	}
	tile.setAttribute( 'aria-label', tile.title );
	tile.innerHTML = data.icons?.basic || '';
	preview.appendChild( tile );
}

if ( media && media.view && media.view.Attachment ) {
	const Attachment = media.view.Attachment;
	const render = Attachment.prototype.render;
	const initialize = Attachment.prototype.initialize;
	Attachment.prototype.initialize = function ( ...args ) {
		initialize.apply( this, args );
		this.listenTo( this.model, 'change:aiAct', () => decorate( this ) );
	};
	Attachment.prototype.render = function ( ...args ) {
		const out = render.apply( this, args );
		decorate( this );
		return out;
	};

	const Filters = media.view.AttachmentFilters.extend( {
		id: 'media-attachment-ai-act-filter',
		createFilters() {
			this.filters = {};
			Object.entries( data.filters || {} ).forEach(
				( [ value, text ], i ) => {
					this.filters[ value || 'all' ] = {
						text,
						props: { ai_act: value },
						priority: 10 + i,
					};
				}
			);
		},
	} );

	const Browser = media.view.AttachmentsBrowser;
	const createToolbar = Browser.prototype.createToolbar;
	Browser.prototype.createToolbar = function ( ...args ) {
		createToolbar.apply( this, args );
		if ( ! this.toolbar || this.toolbar.get( 'aiActFilter' ) ) {
			return;
		}
		this.toolbar.set(
			'aiActFilterLabel',
			new media.view.Label( {
				value: data.label || 'AI',
				attributes: { for: 'media-attachment-ai-act-filter' },
				priority: -72,
			} ).render()
		);
		this.toolbar.set(
			'aiActFilter',
			new Filters( {
				controller: this.controller,
				model: this.collection.props,
				priority: -72,
			} ).render()
		);
	};
}

// The way to the tool, beside „Datei hinzufügen“.
if ( document.body.classList.contains( 'upload-php' ) && data.tool ) {
	const add = document.querySelector( '.wrap .page-title-action' );
	if ( add && ! document.querySelector( '.ai-act-title-action' ) ) {
		const link = document.createElement( 'a' );
		link.className = 'page-title-action ai-act-title-action';
		link.href = data.tool;
		link.innerHTML = `${ data.icons?.basic || '' }<span>${
			data.label || ''
		}</span>`;
		add.after( link );
	}
}

/**
 * „Bild analysieren (KI)“ in the box, in the modal and on the edit screen: the analysis runs, then the
 * modal draws the box again from the file; the edit screen shows the reason in place.
 */
document.addEventListener( 'click', ( event ) => {
	const button = event.target.closest( '[data-ai-act-analyze]' );
	if ( ! button ) {
		return;
	}
	const id = Number( button.dataset.aiActAnalyze );
	const status = button.parentElement.querySelector( '.ai-act-box__status' );
	button.disabled = true;
	status.textContent = __( 'Analyzing …', 'ai-act-image-labels' );
	apiFetch( {
		path: '/ai-act-labels/v1/review',
		method: 'POST',
		data: { id },
	} )
		.then( ( r ) => {
			status.textContent = '';
			const box = button.closest( '.ai-act-box' );
			box.querySelector( '.ai-act-box__note.is-analysis' )?.remove();
			const note = document.createElement( 'p' );
			note.className = 'ai-act-box__note is-analysis';
			const b = document.createElement( 'b' );
			b.textContent =
				__( 'Image analysis (AI):', 'ai-act-image-labels' ) + ' ';
			note.append( b, r.hint?.reason || '' );
			button.parentElement.before( note );
			// The tile in the grid follows the new suggestion.
			media?.attachment?.( id )?.fetch();
		} )
		.catch( ( e ) => {
			status.textContent = `${ e.message || '' } `;
			if ( e.code === 'ai_act_labels_no_provider' && data.connectors ) {
				const link = document.createElement( 'a' );
				link.href = data.connectors;
				link.textContent = __(
					'Open Connectors',
					'ai-act-image-labels'
				);
				status.append( link );
			}
		} )
		.finally( () => {
			button.disabled = false;
		} );
} );
