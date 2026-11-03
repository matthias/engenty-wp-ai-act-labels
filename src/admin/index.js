import { createRoot, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { api, boot, problem } from './api';
import { Mark } from './parts';
import { Button, Notice, Spinner } from './ui';
import Review from './Review';
import Look from './Look';
import Knowledge from './Knowledge';
import './admin.css';

const TABS = [
	[ 'review', __( 'Review', 'ai-act-image-labels' ) ],
	[ 'look', __( 'Appearance', 'ai-act-image-labels' ) ],
	[ 'knowledge', __( 'Knowledge', 'ai-act-image-labels' ) ],
];

/**
 * WordPress' URL follows the tab, so a reload or a shared link opens it again.
 * @param tab
 */
function remember( tab ) {
	const url = new URL( window.location.href );
	url.searchParams.set( 'tab', tab );
	url.searchParams.delete( 'filter' );
	window.history.replaceState( null, '', url );
}

function App( { tab: initialTab, filter } ) {
	const [ tab, setTab ] = useState(
		TABS.some( ( [ k ] ) => k === initialTab ) ? initialTab : 'review'
	);
	const [ state, setState ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		api( '/state' )
			.then( setState )
			.catch( ( e ) => setError( problem( e ).message ) );
	}, [] );

	const go = ( next ) => {
		setTab( next );
		remember( next );
		window.scrollTo( 0, 0 );
	};

	const open = state ? state.counts.open : 0;
	return (
		<div className="aal-app">
			<header className="aal-top">
				<div className="aal-crumb">
					<Mark size={ 30 } />
					<span className="aal-crumb__brand">
						{ __( 'AI labels', 'ai-act-image-labels' ) }
					</span>
				</div>
				<div className="aal-tabs aal-tabs--top" role="tablist">
					{ TABS.map( ( [ key, name ] ) => (
						<button
							key={ key }
							type="button"
							role="tab"
							aria-selected={ tab === key ? 'true' : 'false' }
							className={ tab === key ? 'is-on' : '' }
							onClick={ () => go( key ) }
						>
							{ name }
							{ key === 'review' && open ? (
								<span className="aal-count">{ open }</span>
							) : null }
						</button>
					) ) }
				</div>
				<span className="aal-top__space" />
				<span
					className="aal-pill-status is-law"
					title={ __(
						'Article 50 of the AI Regulation (EU) 2024/1689',
						'ai-act-image-labels'
					) }
				>
					<span className="aal-dot" />
					<span className="aal-law-long">
						{ __(
							'AI Act Art. 50 applies since 2 Aug 2026',
							'ai-act-image-labels'
						) }
					</span>
					<span className="aal-law-short">
						{ __( 'AI Act Art. 50', 'ai-act-image-labels' ) }
					</span>
				</span>
				<Button size="sm" href={ boot.upload }>
					{ __( 'Media Library', 'ai-act-image-labels' ) }
				</Button>
			</header>
			<main className="aal-main">
				{ error ? <Notice tone="error">{ error }</Notice> : null }
				{ ! state && ! error ? (
					<Spinner
						label={ __(
							'AI labels are loading …',
							'ai-act-image-labels'
						) }
					/>
				) : null }
				{ state && tab === 'review' ? (
					<Review
						state={ state }
						setState={ setState }
						initial={ filter }
					/>
				) : null }
				{ state && tab === 'look' ? (
					<Look state={ state } setState={ setState } />
				) : null }
				{ state && tab === 'knowledge' ? (
					<Knowledge state={ state } />
				) : null }
			</main>
		</div>
	);
}

const root = document.getElementById( 'ai-act-labels-app' );
if ( root ) {
	createRoot( root ).render(
		<App
			tab={ root.dataset.tab || 'review' }
			filter={ root.dataset.filter || '' }
		/>
	);
}
