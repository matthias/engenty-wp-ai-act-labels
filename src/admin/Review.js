import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { api, boot, post, problem, query } from './api';
import { undoAccepted } from './undo.mjs';
import { SetupDialog } from './Assistant';
import { Run, prefetch } from './Run';
import {
	Decide,
	EuIcon,
	HintLine,
	Promo,
	Thumb,
	Traces,
	UsageLine,
} from './parts';
import { Button, Card, Icon, Notice, Section, Spinner, when } from './ui';

/** The lists, in the order of their tabs. */
const LISTS = [
	[ 'suggested', __( 'Suggestions', 'ai-act-labels' ) ],
	[ 'unchecked', __( 'Used, not decided', 'ai-act-labels' ) ],
	[ 'labelled', __( 'Labeled', 'ai-act-labels' ) ],
	[ 'none', __( 'No label needed', 'ai-act-labels' ) ],
	[ 'all', __( 'All', 'ai-act-labels' ) ],
];

/** What each list says when it is empty. */
const EMPTY = {
	suggested: __(
		'No file carries traces of AI without a decision. Traces are often missing – go through “Used, not decided” too.',
		'ai-act-labels'
	),
	unchecked: __(
		'Everything used on your site has a decision. Or the site has not been checked yet.',
		'ai-act-labels'
	),
	labelled: __( 'No file labeled yet.', 'ai-act-labels' ),
	none: __( 'No file decided as needing no label yet.', 'ai-act-labels' ),
	all: __( 'No images, videos or recordings.', 'ai-act-labels' ),
};

/** Files per page. */
const PER = 24;

/**
 * Whether a file still belongs to a list after what was decided here: one that left it stays on the
 * page, but the next page starts that much earlier.
 * @param list
 * @param item
 */
const inList = ( list, item ) => {
	const kinds = [ 'generated', 'modified' ];
	switch ( list ) {
		case 'suggested':
			return ! item.state && kinds.includes( item.suggests );
		case 'unchecked':
			return ! item.state && Object.keys( item.usage || {} ).length > 0;
		case 'labelled':
			return kinds.includes( item.state );
		case 'none':
			return item.state === 'none';
	}
	return true;
};

/**
 * The numbers and the check of the whole site.
 * @param root0
 * @param root0.state
 * @param root0.onDone  Called with the new counts when the check is through.
 * @param root0.list    The list open below.
 * @param root0.onOpen  Opens a list: each number opens its own.
 * @param root0.analyze The button that opens the image analysis, beside the check.
 * @param root0.panel   The image analysis, when open.
 */
function Overview( { state, onDone, list, onOpen, analyze, panel } ) {
	const c = state.counts;
	const [ run, setRun ] = useState( null );
	const [ error, setError ] = useState( null );
	const labelled = c.generated + c.modified;

	const step = useCallback(
		( first ) => {
			// Files read before, too: what the detection knows grows with updates.
			post( '/scan', { restart: first, files: true } )
				.then( ( p ) => {
					setRun( p );
					if ( p.phase === 'done' ) {
						onDone( p );
						window.setTimeout( () => setRun( null ), 1600 );
					} else {
						step( false );
					}
				} )
				.catch( ( e ) => {
					setError( problem( e ).message );
					setRun( null );
				} );
		},
		[ onDone ]
	);

	// A check that was cut off goes on.
	const resumed = useRef( false );
	useEffect( () => {
		if ( state.canSettings && state.running && ! resumed.current ) {
			resumed.current = true;
			step( false );
		}
	}, [ state.canSettings, state.running, step ] );

	const phase = run
		? {
				posts: __( 'Reading your pages and posts …', 'ai-act-labels' ),
				files: __(
					'Reading the traces in your files …',
					'ai-act-labels'
				),
				done: __( 'Done.', 'ai-act-labels' ),
		  }[ run.phase ]
		: '';
	const pct =
		run && run.total ? Math.round( ( run.done / run.total ) * 100 ) : 0;

	return (
		<div className="aal-overview">
			<div className="aal-overview__nums">
				{ /* In the order of the tabs below. */ }
				{ [
					[
						'suggested',
						c.suggested,
						c.suggested > 0,
						__( 'Suggestions', 'ai-act-labels' ),
						__( 'traces of AI, no decision', 'ai-act-labels' ),
					],
					[
						'unchecked',
						c.unchecked,
						c.unchecked > 0,
						__( 'used, not decided', 'ai-act-labels' ),
						__( 'visible on your site', 'ai-act-labels' ),
					],
					[
						'labelled',
						labelled,
						false,
						__( 'labeled', 'ai-act-labels' ),
						sprintf(
							/* translators: 1: generated count, 2: modified count */
							__(
								'%1$d AI-generated · %2$d modified',
								'ai-act-labels'
							),
							c.generated,
							c.modified
						),
					],
				].map( ( [ key, n, open, name, more ] ) => (
					<button
						key={ key }
						type="button"
						className={ `aal-num${ open ? ' is-open' : '' }${
							list === key ? ' is-on' : ''
						}` }
						aria-pressed={ list === key ? 'true' : 'false' }
						onClick={ () => onOpen( key ) }
					>
						<span>{ n }</span>
						<span className="aal-num__text">
							{ name }
							<em>{ more }</em>
						</span>
					</button>
				) ) }
			</div>
			<div className="aal-overview__check">
				{ run ? (
					<>
						<span className="aal-fine">{ phase }</span>
						<span className="aal-bar">
							<i
								style={ {
									width: `${
										run.phase === 'done' ? 100 : pct
									}%`,
								} }
							/>
						</span>
					</>
				) : (
					<>
						<span className="aal-fine">
							{ state.last
								? sprintf(
										/* translators: 1: date, 2: pages, 3: files in use */
										__(
											'Last checked %1$s: %2$d pages and posts, %3$d files in use.',
											'ai-act-labels'
										),
										when( state.last.at ),
										state.last.posts,
										state.last.used
								  )
								: __(
										'Not checked yet: where your media is used and which traces of AI it carries.',
										'ai-act-labels'
								  ) }
						</span>
						<span className="aal-row-inline">
							{ state.last ? analyze : null }
							<Button
								disabled={ ! state.canSettings }
								kind="primary"
								icon="refresh"
								onClick={ () => step( true ) }
							>
								{ state.last
									? __( 'Quick check', 'ai-act-labels' )
									: __( 'Check site', 'ai-act-labels' ) }
							</Button>
						</span>
					</>
				) }
				{ error ? <Notice tone="error">{ error }</Notice> : null }
			</div>
			{ panel }
		</div>
	);
}

/**
 * One file in the list: its name opens it in the media library, the decision and the image analysis
 * right here; a labelled file that is used can be checked on the site.
 * @param root0
 * @param root0.item
 * @param root0.busy
 * @param root0.onLabel
 * @param root0.onReview
 * @param root0.reviewing
 * @param root0.onVerify
 * @param root0.verifying
 */
function MediaRow( {
	item,
	busy,
	onLabel,
	onReview,
	reviewing,
	onVerify,
	verifying,
} ) {
	const labelled = item.state === 'generated' || item.state === 'modified';
	return (
		<div className={ `aal-mrow is-${ item.state || 'open' }` }>
			<span className="aal-mrow__main">
				<a
					href={ `${ boot.item }${ item.id }` }
					className="aal-mrow__thumb"
					aria-label={ __(
						'Open in the Media Library',
						'ai-act-labels'
					) }
				>
					<Thumb item={ item } />
				</a>
				<span className="aal-mrow__t">
					<b>
						<a href={ `${ boot.item }${ item.id }` }>
							{ item.title || item.file }
						</a>
						<i>{ item.file }</i>
					</b>
					<UsageLine usage={ item.usage } />
					<Traces signals={ item.signals } />
					<HintLine hint={ item.hint } conflict={ item.conflict } />
				</span>
			</span>
			<span className="aal-mrow__right">
				<span className="aal-mrow__acts">
					<span className="aal-mrow__btns">
						{ labelled && item.usage?.length ? (
							<Button
								kind="ghost"
								size="sm"
								icon="eye"
								busy={ verifying }
								onClick={ () => onVerify( item ) }
							>
								{ __( 'Check on the site', 'ai-act-labels' ) }
							</Button>
						) : null }
						<Button
							kind="ghost"
							size="sm"
							icon="sparkle"
							busy={ reviewing }
							onClick={ () => onReview( item ) }
						>
							{ __( 'Analyze image (AI)', 'ai-act-labels' ) }
						</Button>
					</span>
					<Decide
						value={ item.state }
						suggests={ item.suggests }
						busy={ busy }
						onChange={ ( st ) => onLabel( [ item.id ], st ) }
					/>
				</span>
			</span>
		</div>
	);
}

/**
 * Prüfen: the numbers and the files by list.
 * @param root0
 * @param root0.state
 * @param root0.setState
 * @param root0.initial  The list to start with.
 */
export default function Review( { state, setState, initial } ) {
	const firstList =
		initial && LISTS.some( ( [ k ] ) => k === initial )
			? initial
			: state.counts.suggested
			? 'suggested'
			: state.counts.unchecked
			? 'unchecked'
			: 'all';
	const [ list, setList ] = useState( firstList );
	const [ search, setSearch ] = useState( '' );
	const [ offset, setOffset ] = useState( 0 );
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( {} );
	const [ verifying, setVerifying ] = useState( {} );
	const [ reviewing, setReviewing ] = useState( {} );
	const [ runOpen, setRunOpen ] = useState( false );
	const [ accepted, setAccepted ] = useState( null );
	const [ accepting, setAccepting ] = useState( false );
	const [ undoing, setUndoing ] = useState( false );
	const [ setup, setSetup ] = useState( false );
	const ready = state.assistant.ready;

	// A running image analysis opens its panel, also in a window that did not start it.
	useEffect( () => {
		if ( ready && state.last ) {
			prefetch().then( ( p ) => {
				if ( [ 'running', 'stalled' ].includes( p.job?.status ) ) {
					setRunOpen( true );
				}
			} );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps -- once, when the tool opens.
	}, [] );

	/** Every suggestion at once; the notice offers to take it back. */
	const acceptAll = () => {
		setAccepting( true );
		post( '/accept' )
			.then( ( r ) => {
				setAccepted( r.done );
				setState( ( s ) => ( { ...s, counts: r.counts } ) );
				load();
			} )
			.catch( ( e ) => setError( problem( e ).message ) )
			.finally( () => setAccepting( false ) );
	};
	const undoAccept = async () => {
		if ( undoing ) {
			return;
		}
		setUndoing( true );
		try {
			await undoAccepted(
				accepted || [],
				async ( ids ) => {
					const r = await post( '/label', {
						ids,
						state: '',
						via: 'bulk',
					} );
					replace( r.items );
					setState( ( s ) => ( { ...s, counts: r.counts } ) );
					return r.items.map( ( item ) => item.id );
				},
				( remaining ) =>
					setAccepted( remaining.length ? remaining : null )
			);
		} catch ( e ) {
			setError(
				e.message === 'ai_act_labels_undo_incomplete'
					? __(
							'Some labels could not be undone. Retry the remaining files.',
							'ai-act-labels'
					  )
					: problem( e ).message
			);
		} finally {
			setUndoing( false );
			load();
		}
	};

	const load = useCallback( () => {
		api( `/media${ query( { filter: list, search, offset, per: PER } ) }` )
			.then( setData )
			.catch( ( e ) => setError( problem( e ).message ) );
	}, [ list, search, offset ] );

	useEffect( () => {
		const t = window.setTimeout( load, search ? 300 : 0 );
		return () => window.clearTimeout( t );
	}, [ load, search ] );

	const replace = ( items ) => {
		setData( ( d ) =>
			d
				? {
						...d,
						items: d.items.map(
							( it ) =>
								items.find( ( n ) => n.id === it.id ) || it
						),
				  }
				: d
		);
	};

	const label = ( ids, st, via = 'manual' ) => {
		setBusy( ( b ) => ( {
			...b,
			...Object.fromEntries( ids.map( ( id ) => [ id, true ] ) ),
		} ) );
		return post( '/label', { ids, state: st, via } )
			.then( ( r ) => {
				replace( r.items );
				setState( ( s ) => ( { ...s, counts: r.counts } ) );
			} )
			.catch( ( e ) => setError( problem( e ).message ) )
			.finally( () =>
				setBusy( ( b ) => {
					const n = { ...b };
					ids.forEach( ( id ) => delete n[ id ] );
					return n;
				} )
			);
	};

	const review = ( item ) => {
		setReviewing( ( r ) => ( { ...r, [ item.id ]: true } ) );
		return post( '/review', { id: item.id } )
			.then( ( r ) => {
				replace( [ r.item ] );
				setState( ( s ) => ( { ...s, counts: r.counts } ) );
			} )
			.catch( ( e ) => setError( problem( e ).message ) )
			.finally( () =>
				setReviewing( ( r ) => {
					const n = { ...r };
					delete n[ item.id ];
					return n;
				} )
			);
	};

	/**
	 * Opens the pages the file is used on and looks for its label there.
	 * @param item
	 */
	const verify = ( item ) => {
		setVerifying( ( v ) => ( { ...v, [ item.id ]: true } ) );
		return post( '/verify', { id: item.id } )
			.then( ( r ) => replace( [ r.item ] ) )
			.catch( ( e ) => setError( problem( e ).message ) )
			.finally( () =>
				setVerifying( ( v ) => {
					const n = { ...v };
					delete n[ item.id ];
					return n;
				} )
			);
	};

	const onScanned = useCallback(
		( p ) => {
			setState( ( s ) => ( {
				...s,
				counts: p.counts,
				last: p.last,
				running: false,
			} ) );
			load();
		},
		[ setState, load ]
	);

	const counts = state.counts;
	const countOf = {
		suggested: counts.suggested,
		unchecked: counts.unchecked,
		labelled: counts.generated + counts.modified,
		none: counts.none,
	};
	const shown = data?.items || [];
	const gone = shown.filter( ( i ) => ! inList( list, i ) ).length;
	const total = data ? data.total - gone : 0;
	const next = offset + shown.length - gone;
	const pages = Math.ceil( total / PER );

	return (
		<>
			<Overview
				state={ state }
				onDone={ onScanned }
				list={ list }
				onOpen={ ( key ) => {
					setList( key );
					setOffset( 0 );
				} }
				analyze={
					<Button
						icon="sparkle"
						className={ runOpen ? 'is-on' : '' }
						aria-expanded={ runOpen ? 'true' : 'false' }
						// The panel's numbers are on their way before the click.
						onMouseEnter={ () => ready && ! runOpen && prefetch() }
						onFocus={ () => ready && ! runOpen && prefetch() }
						onClick={ () =>
							ready ? setRunOpen( ! runOpen ) : setSetup( true )
						}
					>
						{ __( 'Analyze image content (AI)', 'ai-act-labels' ) }
					</Button>
				}
				panel={
					runOpen && ready ? (
						<Run
							providers={ state.assistant.providers }
							onCounts={ ( c ) =>
								setState( ( s ) => ( { ...s, counts: c } ) )
							}
							onDone={ load }
						/>
					) : null
				}
			/>
			<Section
				title={ __( 'Media', 'ai-act-labels' ) }
				hint={ __(
					'Decide per file: AI-generated, modified with AI or no label needed. The label is required when something looks real that did not happen that way.',
					'ai-act-labels'
				) }
				aside={
					<span className="aal-searchbox">
						<Icon name="search" size={ 15 } />
						<input
							type="search"
							value={ search }
							placeholder={ __( 'Search', 'ai-act-labels' ) }
							aria-label={ __( 'Search media', 'ai-act-labels' ) }
							onChange={ ( e ) => {
								setSearch( e.target.value );
								setOffset( 0 );
							} }
						/>
					</span>
				}
			>
				<div className="aal-toolbar">
					<div className="aal-tabs" role="tablist">
						{ LISTS.map( ( [ key, name ] ) => (
							<button
								key={ key }
								type="button"
								role="tab"
								aria-selected={
									list === key ? 'true' : 'false'
								}
								className={ list === key ? 'is-on' : '' }
								onClick={ () => {
									setList( key );
									setOffset( 0 );
								} }
							>
								{ name }
								{ countOf[ key ] ? (
									<span className="aal-count">
										{ countOf[ key ] }
									</span>
								) : null }
							</button>
						) ) }
					</div>
					{ list === 'suggested' && counts.suggested > 0 ? (
						<Button
							kind="primary"
							size="sm"
							icon="check"
							busy={ accepting }
							disabled={ undoing }
							onClick={ acceptAll }
						>
							{ sprintf(
								/* translators: %d: number of suggestions */
								__(
									'Apply all %d suggestions',
									'ai-act-labels'
								),
								counts.suggested
							) }
						</Button>
					) : null }
				</div>
				{ error ? <Notice tone="error">{ error }</Notice> : null }
				{ accepted ? (
					<Notice tone="success">
						{ sprintf(
							/* translators: %d: number of files */
							__( '%d suggestions applied.', 'ai-act-labels' ),
							accepted.length
						) }{ ' ' }
						<button
							type="button"
							className="aal-link-btn"
							onClick={ undoAccept }
							disabled={ undoing }
						>
							{ __( 'Undo', 'ai-act-labels' ) }
						</button>
					</Notice>
				) : null }
				{ ! data ? (
					<Spinner label={ __( 'Loading …', 'ai-act-labels' ) } />
				) : data.items.length ? (
					<Card pad0>
						{ data.items.map( ( item ) => (
							<MediaRow
								key={ item.id }
								item={ item }
								busy={ !! busy[ item.id ] }
								onLabel={ label }
								onReview={ ( it ) =>
									ready ? review( it ) : setSetup( true )
								}
								reviewing={ !! reviewing[ item.id ] }
								onVerify={ verify }
								verifying={ !! verifying[ item.id ] }
							/>
						) ) }
					</Card>
				) : (
					<div className="aal-empty">
						<EuIcon name="basic" />
						<p>{ EMPTY[ list ] }</p>
					</div>
				) }
				{ pages > 1 ? (
					<div className="aal-row-inline aal-pager">
						<Button
							size="sm"
							disabled={ offset <= 0 }
							onClick={ () =>
								setOffset( Math.max( 0, offset - PER ) )
							}
						>
							←
						</Button>
						<span className="aal-fine">
							{ sprintf(
								/* translators: 1: page, 2: pages */
								__( 'Page %1$d of %2$d', 'ai-act-labels' ),
								Math.floor( offset / PER ) + 1,
								pages
							) }
						</span>
						<Button
							size="sm"
							disabled={ next >= total }
							onClick={ () => setOffset( next ) }
						>
							→
						</Button>
					</div>
				) : null }
				<span className="aal-fine">
					{ __(
						'Also in the Media Library: the “AI” filter, a column and bulk actions in the list view.',
						'ai-act-labels'
					) }{ ' ' }
					<a href={ boot.upload }>
						{ __( 'Open Media Library', 'ai-act-labels' ) }
					</a>
				</span>
			</Section>
			<Promo promo={ state.promo } />
			{ setup ? (
				<SetupDialog
					assistant={ state.assistant }
					onClose={ () => setSetup( false ) }
				/>
			) : null }
		</>
	);
}
