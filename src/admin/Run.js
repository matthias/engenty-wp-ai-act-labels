import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { api, post, problem, query } from './api';
import { Button, Notice } from './ui';

/** Image generators for everyone came in the summer of 2022: older uploads rarely need a check. */
const FROM = '2022-08-01';

/** Pictures in the test. */
const STEP = 10;

/** Milliseconds between two looks at a running analysis. */
const POLL = 3000;

/** The pictures it looks at: used on the site, no decision yet. */
const LIST = 'unchecked';

const load = () => api( `/run${ query( { filter: LIST } ) }` );

/** The numbers asked for before the panel opens, so it opens with them. */
let ahead = null;

/**
 * Asks for the numbers of the panel ahead of time.
 * @return {Promise} The answer; failures are left to the panel.
 */
export const prefetch = () => {
	ahead = load();
	return ahead.catch( () => ( {} ) );
};

/** Year markers closer than this (percent of the track) to the one before are left out. */
const GAP = 6;

const lang = () => document.documentElement.lang || undefined;

/**
 * A day as the site's language writes it.
 * @param ymd YYYY-MM-DD.
 */
const day = ( ymd ) =>
	new Date( `${ ymd }T12:00:00` ).toLocaleDateString( lang(), {
		day: 'numeric',
		month: 'long',
		year: 'numeric',
	} );

/**
 * US dollars as the provider bills them.
 * @param usd
 */
const money = ( usd ) =>
	new Intl.NumberFormat( lang(), {
		style: 'currency',
		currency: 'USD',
		maximumFractionDigits: usd < 1 ? 3 : 2,
	} ).format( usd );

/**
 * How long something takes, in seconds, minutes or hours.
 * @param seconds
 */
const duration = ( seconds ) => {
	if ( seconds < 90 ) {
		const s = Math.max( 1, Math.round( seconds ) );
		return sprintf(
			/* translators: %d: seconds */
			_n( '%d second', '%d seconds', s, 'ai-act-image-labels' ),
			s
		);
	}
	const minutes = Math.round( seconds / 60 );
	return minutes < 90
		? sprintf(
				/* translators: %d: minutes */
				_n( '%d minute', '%d minutes', minutes, 'ai-act-image-labels' ),
				minutes
		  )
		: sprintf(
				/* translators: %s: hours, e.g. 2.5 */
				__( '%s hours', 'ai-act-image-labels' ),
				( minutes / 60 ).toLocaleString( lang(), {
					maximumFractionDigits: 1,
				} )
		  );
};

/**
 * What a number of analyses took or will take: cost where the provider tells it, tokens, time.
 * @param n
 * @param per Per analysis: `tokens`, `seconds`, `cost` (null when unknown).
 */
const takes = ( n, per ) =>
	[
		null === per.cost ? null : money( n * per.cost ),
		sprintf(
			/* translators: %s: number of tokens */
			__( '%s tokens', 'ai-act-image-labels' ),
			Math.round( n * per.tokens ).toLocaleString( lang() )
		),
		duration( n * per.seconds ),
	]
		.filter( Boolean )
		.join( ' · ' );

/**
 * The image analysis of the pictures used on the site: how far back, a test with ten pictures, then what
 * all of them would take, measured in that test – or, when the test is skipped, in this month's earlier
 * analyses. The server runs it with WP-Cron: it goes on when the page closes, and every window shows it.
 * Analysed pictures never come again: a stopped run goes on where it stopped.
 * @param root0
 * @param root0.providers The AI providers WordPress has, by name.
 * @param root0.onCounts  Called with the counts while a run goes.
 * @param root0.onDone    Called when a run ends.
 */
export function Run( { providers, onCounts, onDone } ) {
	const [ plan, setPlan ] = useState( null );
	const [ range, setRange ] = useState( [ null, null ] );
	const [ skipped, setSkipped ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const was = useRef( null );

	const take = ( fresh ) => {
		setPlan( fresh );
		onCounts( fresh.counts );
		const status = fresh.job?.status;
		if ( was.current === 'running' && status !== 'running' ) {
			onDone();
		}
		was.current = status;
	};
	useEffect( () => {
		const asked = ahead || load();
		ahead = null;
		asked.then( take ).catch( ( e ) => setError( problem( e ).message ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps -- once, when it opens.
	}, [] );

	// While a run goes, a look every few seconds: how far it is, and WP-Cron gets a push.
	const job = plan?.job;
	const active =
		job && ( job.status === 'running' || job.status === 'stalled' );
	useEffect( () => {
		if ( ! active ) {
			return undefined;
		}
		const t = window.setInterval(
			() =>
				load()
					.then( take )
					.catch( ( e ) => setError( problem( e ).message ) ),
			POLL
		);
		return () => window.clearInterval( t );
		// eslint-disable-next-line react-hooks/exhaustive-deps -- the look only depends on whether a run goes.
	}, [ active ] );

	const head = (
		<div className="aal-run__head">
			<strong>
				{ __( 'Analyze image content (AI)', 'ai-act-image-labels' ) }
			</strong>
			<span className="aal-fine">
				{ sprintf(
					/* translators: %s: names of the AI providers */
					__(
						'The AI looks at each picture used on your site that has no decision yet and suggests: AI-generated, modified with AI or no label needed – you decide. WordPress sends each picture, 768 pixels wide, with its name and where it is used to your AI provider (%s). The provider bills each analysis to your account with it; this plugin and engenty charge nothing.',
						'ai-act-image-labels'
					),
					( providers || [] ).join( ', ' )
				) }
			</span>
		</div>
	);
	if ( ! plan ) {
		return (
			<div className="aal-run" aria-busy="true">
				{ head }
				{ error ? (
					<Notice tone="error">{ error }</Notice>
				) : (
					// Bars where the days, the track, the years, the numbers and the buttons come.
					<div className="aal-run__wait">
						<i style={ { width: '220px' } } />
						<i className="is-track" />
						<i style={ { width: '100%', height: '12px' } } />
						<i style={ { width: '340px' } } />
						<i style={ { width: '480px', height: '12px' } } />
						<span className="aal-row-inline">
							<i className="is-button" />
							<i className="is-button" />
						</span>
					</div>
				) }
			</div>
		);
	}
	const days = Object.keys( plan.days );
	if ( ! days.length ) {
		return (
			<div className="aal-run">
				{ head }
				<p className="aal-fine">
					{ __(
						'Every picture used on your site has a decision or an assessment.',
						'ai-act-image-labels'
					) }
				</p>
			</div>
		);
	}
	// Without a test: what the analyses of this month took so far, when there were any.
	const calls = plan.calls;
	const earlier = calls.n
		? {
				n: calls.n,
				tokens: calls.tokens / calls.n,
				seconds: calls.seconds / calls.n,
				cost: calls.costed ? calls.cost / calls.costed : null,
		  }
		: null;

	// The track is as long as the pictures: each upload day as wide as it has pictures, the newest right;
	// in each day the open pictures first, then the analysed ones in green.
	const open = days.map( ( m ) => plan.days[ m ][ 0 ] );
	const analysed = days.map( ( m ) => plan.days[ m ][ 1 ] );
	const cum = days.reduce(
		( all, m, i ) => [
			...all,
			i ? all[ i - 1 ] + open[ i - 1 ] + analysed[ i - 1 ] : 0,
		],
		[]
	);
	const last = days.length - 1;
	const total = cum[ last ] + open[ last ] + analysed[ last ];
	const pct = ( n ) => ( n / total ) * 100;
	const pos = ( i ) => pct( cum[ i ] );
	const end = ( i ) => pct( cum[ i ] + open[ i ] + analysed[ i ] );
	// From August 2022, up to the last day with open pictures: where the green begins.
	const first = days.findIndex( ( m ) => m >= FROM );
	const upto = open.findLastIndex( ( n ) => n > 0 );
	const ran = ( m, fallback ) => {
		const i = active && m ? days.findIndex( ( x ) => x >= m ) : -1;
		return i >= 0 ? i : fallback;
	};
	const lo = Math.min(
		range[ 0 ] ?? ran( job?.since, first < 0 ? 0 : first ),
		last
	);
	const hi = Math.max(
		lo,
		Math.min(
			range[ 1 ] ?? ran( job?.until, upto < 0 ? last : upto ),
			last
		)
	);
	const count = open.slice( lo, hi + 1 ).reduce( ( a, n ) => a + n, 0 );
	const done = analysed.reduce( ( a, n ) => a + n, 0 );
	// Neighbouring pieces of one colour become one, so the rail has few colour stops, not one per day.
	const pieces = [];
	days.forEach( ( m, i ) => {
		const mid = pct( cum[ i ] + open[ i ] );
		const tone =
			i >= lo && i <= hi ? 'var(--aal-ember)' : 'var(--aal-line)';
		for ( const [ c, a, b ] of [
			[ tone, pos( i ), mid ],
			[ 'var(--aal-moss)', mid, end( i ) ],
		] ) {
			const prev = pieces[ pieces.length - 1 ];
			if ( b <= a ) {
				continue;
			}
			if ( prev && prev[ 0 ] === c ) {
				prev[ 2 ] = b;
			} else {
				pieces.push( [ c, a, b ] );
			}
		}
	} );
	const rail = pieces
		.map( ( [ c, a, b ] ) => `${ c } ${ a }%, ${ c } ${ b }%` )
		.join( ', ' );
	const years = [];
	days.forEach( ( m, i ) => {
		const y = m.slice( 0, 4 );
		const prev = years[ years.length - 1 ];
		if (
			( ! prev || prev.y !== y ) &&
			( ! prev || pos( i ) - prev.at >= GAP )
		) {
			years.push( { y, i, at: pos( i ) } );
		}
	} );
	// A value on the track to a day: the first handle to a day's start, the second to its end.
	const toLo = ( v ) =>
		Math.min(
			hi,
			Math.max(
				0,
				cum.findLastIndex( ( c ) => c <= v )
			)
		);
	const toHi = ( v ) => {
		const i = days.findIndex(
			( m, j ) => cum[ j ] + open[ j ] + analysed[ j ] >= v
		);
		return Math.max( lo, i < 0 ? last : i );
	};

	const start = ( limit, isTest ) => {
		setError( null );
		setBusy( true );
		post( '/run/start', {
			since: days[ lo ],
			until: days[ hi ],
			limit: Math.min( limit, count ),
			test: isTest,
		} )
			.then( ( r ) => {
				was.current = 'running';
				setPlan( ( p ) => ( { ...p, job: r.job } ) );
			} )
			.catch( ( e ) => setError( problem( e ).message ) )
			.finally( () => setBusy( false ) );
	};
	const halt = () => {
		setBusy( true );
		post( '/run/stop' )
			.then( () => load().then( take ) )
			.catch( ( e ) => setError( problem( e ).message ) )
			.finally( () => setBusy( false ) );
	};

	const uploaded = sprintf(
		/* translators: 1: first day, 2: last day */
		__( 'Uploaded from %1$s to %2$s', 'ai-act-image-labels' ),
		day( days[ lo ] ),
		day( days[ hi ] )
	);
	const running = active ? job : null;
	// The last test, from whichever window started it.
	const test = job?.measured || null;
	const estimate = test || ( skipped ? earlier : null );
	return (
		<div className="aal-run">
			{ head }
			<div className="aal-run__since">
				<strong>{ uploaded }</strong>
				<div className="aal-run__track">
					<span
						className="aal-run__rail"
						style={ {
							background: `linear-gradient(to right, ${ rail })`,
						} }
					/>
					<input
						type="range"
						min={ 0 }
						max={ total }
						value={ cum[ lo ] }
						aria-label={ __( 'From', 'ai-act-image-labels' ) }
						aria-valuetext={ day( days[ lo ] ) }
						disabled={ !! running }
						onChange={ ( e ) =>
							setRange( [ toLo( Number( e.target.value ) ), hi ] )
						}
					/>
					<input
						type="range"
						min={ 0 }
						max={ total }
						value={ cum[ hi ] + open[ hi ] + analysed[ hi ] }
						aria-label={ __( 'To', 'ai-act-image-labels' ) }
						aria-valuetext={ day( days[ hi ] ) }
						disabled={ !! running }
						onChange={ ( e ) =>
							setRange( [ lo, toHi( Number( e.target.value ) ) ] )
						}
					/>
					<div className="aal-run__years">
						{ years.map( ( { y, i, at: left } ) => (
							<button
								key={ y }
								type="button"
								disabled={ !! running }
								style={ { left: `${ left }%` } }
								onClick={ () =>
									setRange( [ Math.min( i, hi ), hi ] )
								}
							>
								{ y }
							</button>
						) ) }
					</div>
				</div>
				{ done ? (
					<span className="aal-fine aal-run__done">
						{ sprintf(
							/* translators: %d: number of pictures */
							_n(
								'%d picture already analyzed',
								'%d pictures already analyzed',
								done,
								'ai-act-image-labels'
							),
							done
						) }
					</span>
				) : null }
			</div>
			<p className="aal-run__sum">
				<strong>
					{ sprintf(
						/* translators: %d: number of pictures */
						_n(
							'%d picture without a decision or an assessment',
							'%d pictures without a decision or an assessment',
							count,
							'ai-act-image-labels'
						),
						count
					) }
				</strong>
				{ estimate ? (
					<span>
						{ sprintf(
							/* translators: 1: number of pictures, 2: what they would take */
							__( 'All %1$d: about %2$s', 'ai-act-image-labels' ),
							count,
							takes( count, estimate )
						) }
					</span>
				) : null }
				<span className="aal-fine">
					{ test
						? sprintf(
								/* translators: 1: number of pictures, 2: what they took */
								__(
									'Measured in the test with %1$d pictures: %2$s',
									'ai-act-image-labels'
								),
								test.n,
								takes( test.n, test )
						  )
						: skipped && earlier
						? sprintf(
								/* translators: %d: number of analyses */
								_n(
									'Estimated from %d analysis on this site this month.',
									'Estimated from %d analyses on this site this month.',
									earlier.n,
									'ai-act-image-labels'
								),
								earlier.n
						  )
						: skipped
						? __(
								'No estimate: there was no analysis on this site this month yet.',
								'ai-act-image-labels'
						  )
						: __(
								'First a test with 10 pictures: it shows what the analysis costs on this site, before you start all of them.',
								'ai-act-image-labels'
						  ) }
				</span>
				{ estimate && null === estimate.cost ? (
					<span className="aal-fine">
						{ __(
							'Your provider does not report costs: its price per token tells what this costs.',
							'ai-act-image-labels'
						) }
					</span>
				) : null }
				<span className="aal-fine">
					{ __(
						'Image generators for everyone came in 2022 – older uploads rarely need a check.',
						'ai-act-image-labels'
					) }
				</span>
			</p>
			<div className="aal-row-inline">
				{ running ? (
					<>
						<span className="aal-fine">
							{ sprintf(
								/* translators: 1: done, 2: total */
								__(
									'Analyzing … %1$d of %2$d',
									'ai-act-image-labels'
								),
								running.done,
								running.total
							) }
						</span>
						<Button size="sm" busy={ busy } onClick={ halt }>
							{ __( 'Stop', 'ai-act-image-labels' ) }
						</Button>
						<span className="aal-fine">
							{ running.status === 'stalled'
								? __(
										'Waiting for WP-Cron: the next step comes with the next visit to the site.',
										'ai-act-image-labels'
								  )
								: sprintf(
										/* translators: %s: name of the person who started it */
										__(
											'Runs on the server, started by %s – you can close this page.',
											'ai-act-image-labels'
										),
										running.by
								  ) }
						</span>
					</>
				) : ! count ? (
					<span className="aal-fine">
						{ __(
							'Every picture in this time has a decision or an assessment.',
							'ai-act-image-labels'
						) }
					</span>
				) : (
					<>
						<Button
							size="sm"
							kind={ test || skipped ? 'secondary' : 'primary' }
							icon="sparkle"
							busy={ busy }
							onClick={ () => start( STEP, true ) }
						>
							{ __( 'Test with 10', 'ai-act-image-labels' ) }
						</Button>
						{ ! test && ! skipped ? (
							<Button
								size="sm"
								onClick={ () => setSkipped( true ) }
							>
								{ __( 'Skip test', 'ai-act-image-labels' ) }
							</Button>
						) : null }
						{ ( test || skipped ) && count > 0 ? (
							<Button
								size="sm"
								kind="primary"
								icon="sparkle"
								busy={ busy }
								onClick={ () => start( count, false ) }
							>
								{ sprintf(
									/* translators: %d: number of pictures */
									_n(
										'Analyze %d picture',
										'Analyze %d pictures',
										count,
										'ai-act-image-labels'
									),
									count
								) }
							</Button>
						) : null }
					</>
				) }
			</div>
			{ job?.status === 'failed' ? (
				<Notice tone="error">
					{ sprintf(
						/* translators: %s: the provider's error */
						__(
							'The analysis stopped after three failures in a row: %s',
							'ai-act-image-labels'
						),
						job.error
					) }
				</Notice>
			) : null }
			{ error ? <Notice tone="error">{ error }</Notice> : null }
		</div>
	);
}
