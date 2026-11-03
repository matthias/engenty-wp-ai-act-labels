import { __, _x, sprintf } from '@wordpress/i18n';
import { boot } from './api';
import { ENGENTY_KINDS } from './engenties';
import { Button, Chip, Engenty, Icon } from './ui';

export const STATE_LABELS = {
	generated: __( 'AI-generated', 'ai-act-image-labels' ),
	modified: __( 'Modified with AI', 'ai-act-image-labels' ),
	none: __( 'No label needed', 'ai-act-image-labels' ),
	'': __( 'Not decided', 'ai-act-image-labels' ),
};

/** The same, short: beside the AI icon in the decision. */
const SHORT_LABELS = {
	generated: _x(
		'generated',
		'decision beside the AI icon',
		'ai-act-image-labels'
	),
	modified: _x(
		'modified',
		'decision beside the AI icon',
		'ai-act-image-labels'
	),
	none: _x( 'No label', 'decision', 'ai-act-image-labels' ),
};

/** Where a file is used, in words; `auto`: the label comes there by itself. */
export const CONTEXT_LABELS = {
	featured: __( 'Featured image', 'ai-act-image-labels' ),
	image: __( 'Image', 'ai-act-image-labels' ),
	gallery: __( 'Gallery', 'ai-act-image-labels' ),
	cover: __( 'Cover', 'ai-act-image-labels' ),
	'media-text': __( 'Media & Text', 'ai-act-image-labels' ),
	video: __( 'Video', 'ai-act-image-labels' ),
	audio: __( 'Audio', 'ai-act-image-labels' ),
	url: __( 'only as a URL', 'ai-act-image-labels' ),
	builder: __( 'Page builder', 'ai-act-image-labels' ),
	acf: __( 'ACF field', 'ai-act-image-labels' ),
	shop: __( 'Product gallery', 'ai-act-image-labels' ),
	logo: __( 'Logo', 'ai-act-image-labels' ),
	icon: __( 'Site icon', 'ai-act-image-labels' ),
	header: __( 'Header image', 'ai-act-image-labels' ),
};

/**
 * Inline SVG of one of the EU icons, from PHP.
 * @param root0
 * @param root0.name      basic, generated, modified, sparkle.
 * @param root0.className
 */
export function EuIcon( { name, className = '' } ) {
	return (
		<span
			className={ `aal-eu ${ className }` }
			aria-hidden="true"
			// eslint-disable-next-line react/no-danger -- the Commission's paths, rendered by PHP.
			dangerouslySetInnerHTML={ { __html: boot.icons?.[ name ] || '' } }
		/>
	);
}

/**
 * The plugin's mark: the round engenty with the EU's AI icon as a sticker, away from its one eye.
 * @param root0
 * @param root0.size
 */
export function Mark( { size = 28 } ) {
	return (
		<span className="aal-mark" style={ { width: size, height: size } }>
			<Engenty kind="round" size={ size } />
			<EuIcon name="basic" className="aal-mark__ai" />
		</span>
	);
}

export function StateChip( { state } ) {
	if ( state === 'generated' || state === 'modified' ) {
		return (
			<span className={ `aal-state is-${ state }` }>
				<EuIcon name="basic" />
				{ STATE_LABELS[ state ] }
			</span>
		);
	}
	if ( state === 'none' ) {
		return <Chip>{ STATE_LABELS.none }</Chip>;
	}
	return <Chip tone="amber">{ STATE_LABELS[ '' ] }</Chip>;
}

/**
 * A file's picture, or its type for video and audio.
 * @param root0
 * @param root0.item
 * @param root0.size
 */
export function Thumb( { item, size = 64 } ) {
	const style = { width: size, height: size };
	if ( item.type === 'image' && item.thumb ) {
		return (
			<span className="aal-thumb" style={ style }>
				<img src={ item.thumb } alt="" loading="lazy" />
			</span>
		);
	}
	return (
		<span className="aal-thumb is-file" style={ style }>
			<Icon
				name={ item.type === 'audio' ? 'audio' : 'play' }
				size={ 20 }
			/>
		</span>
	);
}

/**
 * The decision as three buttons.
 * @param root0
 * @param root0.value    The decision.
 * @param root0.suggests What the plugin suggests (marked).
 * @param root0.onChange Called with the new decision ('' when the current one is clicked again).
 * @param root0.busy
 */
export function Decide( { value, suggests, onChange, busy = false } ) {
	return (
		<span
			className="aal-decide"
			role="radiogroup"
			aria-label={ __( 'AI labels', 'ai-act-image-labels' ) }
		>
			{ [ 'generated', 'modified', 'none' ].map( ( s ) => (
				<button
					key={ s }
					type="button"
					role="radio"
					aria-checked={ value === s ? 'true' : 'false' }
					className={ `is-${ s }${ value === s ? ' is-on' : '' }${
						! value && suggests === s ? ' is-suggested' : ''
					}` }
					disabled={ busy }
					onClick={ () => onChange( value === s ? '' : s ) }
					aria-label={ STATE_LABELS[ s ] }
					title={
						! value && suggests === s
							? `${ STATE_LABELS[ s ] } – ${ __(
									'Suggestion',
									'ai-act-image-labels'
							  ) }`
							: STATE_LABELS[ s ]
					}
				>
					{ s !== 'none' ? <EuIcon name="basic" /> : null }
					{ SHORT_LABELS[ s ] }
				</button>
			) ) }
		</span>
	);
}

/**
 * The traces found in a file, as chips.
 * @param root0
 * @param root0.signals
 * @param root0.max
 */
export function Traces( { signals, max = 3 } ) {
	const items = ( signals?.items || [] ).filter( ( i ) => i.label );
	if ( ! items.length ) {
		return null;
	}
	return (
		<span className="aal-traces">
			{ items.slice( 0, max ).map( ( i, n ) => (
				<Chip
					key={ n }
					tone={
						i.kind === 'human'
							? 'moss'
							: i.strength === 'strong'
							? 'ember'
							: 'amber'
					}
					icon={ i.kind === 'human' ? 'check' : 'chip' }
				>
					{ i.label }
				</Chip>
			) ) }
		</span>
	);
}

/** The assistant's assessment in words. */
const DUTY = {
	required: __( 'Label required', 'ai-act-image-labels' ),
	voluntary: __( 'Label voluntary', 'ai-act-image-labels' ),
	none: __( 'no label needed', 'ai-act-image-labels' ),
	unsure: __( 'unclear', 'ai-act-image-labels' ),
};

export function HintLine( { hint } ) {
	if ( ! hint?.reason ) {
		return null;
	}
	return (
		<span className="aal-hint">
			<Engenty kind="drop" size={ 18 } />
			<span>
				<b className={ `is-${ hint.duty }` }>
					{ DUTY[ hint.duty ] || '' }
				</b>{ ' ' }
				{ hint.reason }
				{ hint.question ? <em> { hint.question }</em> : null }
			</span>
		</span>
	);
}

/**
 * Where a file is used, in one line.
 * @param root0
 * @param root0.usage
 */
/** What a check of a page found. */
const CHECKS = {
	shown: __( 'label visible', 'ai-act-image-labels' ),
	missing: __( 'without label', 'ai-act-image-labels' ),
	absent: __( 'not on the page', 'ai-act-image-labels' ),
	error: __( 'page not reachable', 'ai-act-image-labels' ),
};

export function UsageLine( { usage } ) {
	if ( ! usage?.length ) {
		return (
			<span className="aal-fine">
				{ __( 'Not found on any page', 'ai-act-image-labels' ) }
			</span>
		);
	}
	const shown = usage.slice( 0, 3 );
	return (
		<span className="aal-usage">
			{ shown.map( ( u, i ) => (
				<span
					key={ i }
					className={
						CHECKS[ u.check ]
							? `is-${ u.check }`
							: u.auto
							? ''
							: 'is-manual'
					}
				>
					{ u.title }{ ' ' }
					<i>
						({ CONTEXT_LABELS[ u.context ] || u.context }
						{ CHECKS[ u.check ] ? ` · ${ CHECKS[ u.check ] }` : '' }
						)
					</i>
					{ i < shown.length - 1 ? ', ' : '' }
				</span>
			) ) }
			{ usage.length > 3 ? ' ' : '' }
			{ usage.length > 3
				? sprintf(
						/* translators: %d: number of further places */
						__( 'and %d more', 'ai-act-image-labels' ),
						usage.length - 3
				  )
				: '' }
		</span>
	);
}

/**
 * The card from engenty: its words ship with the plugin (includes/class-promo.php), printed as text.
 * @param root0
 * @param root0.promo The card, or null.
 */
export function Promo( { promo } ) {
	if ( ! promo ) {
		return null;
	}
	return (
		<aside className="aal-promo">
			<Engenty
				kind={
					ENGENTY_KINDS.includes( promo.engenty )
						? promo.engenty
						: 'round'
				}
				size={ 40 }
			/>
			<div className="aal-promo__t">
				{ promo.kicker ? (
					<span className="aal-kick">{ promo.kicker }</span>
				) : null }
				<b>{ promo.title }</b>
				{ promo.text ? <p>{ promo.text }</p> : null }
			</div>
			{ promo.button?.url && promo.button?.label ? (
				<Button size="sm" href={ promo.button.url } external>
					{ promo.button.label }
				</Button>
			) : null }
		</aside>
	);
}
