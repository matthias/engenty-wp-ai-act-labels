import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { post, problem } from './api';
import { EuIcon } from './parts';
import {
	Button,
	Card,
	Field,
	Line,
	Notice,
	Section,
	Seg,
	Switch,
	useDraft,
} from './ui';

const ICONS = [
	[ 'official', __( 'EU icon by kind', 'ai-act-image-labels' ), 'generated' ],
	[ 'basic', __( 'Round EU icon', 'ai-act-image-labels' ), 'basic' ],
	[
		'basic-text',
		__( 'Round icon, opens on hover', 'ai-act-image-labels' ),
		'basic',
	],
	[ 'custom', __( 'Own icon', 'ai-act-image-labels' ), '' ],
	[ 'sparkle', __( 'Sparkle', 'ai-act-image-labels' ), 'sparkle' ],
];

const COLORS = [
	[ 'dark', __( 'Dark', 'ai-act-image-labels' ) ],
	[ 'light', __( 'Light', 'ai-act-image-labels' ) ],
	[ 'auto', __( 'Image', 'ai-act-image-labels' ) ],
	[ 'custom', __( 'Color', 'ai-act-image-labels' ) ],
];

const ALPHAS = [ 0, 25, 50, 75 ];

/**
 * Dark or light letters on a picked colour, as the icon has them.
 * @param hex
 */
function ink( hex ) {
	const [ r, g, b ] = [ 1, 3, 5 ].map( ( i ) =>
		parseInt( ( hex || '#000000' ).slice( i, i + 2 ), 16 )
	);
	return ( 0.2126 * r + 0.7152 * g + 0.0722 * b ) / 255 > 0.6
		? '#1d1d1b'
		: '#fff';
}

const POSITIONS = [
	[ 'top-left', __( 'Top left', 'ai-act-image-labels' ) ],
	[ 'top-right', __( 'Top right', 'ai-act-image-labels' ) ],
	[ 'bottom-left', __( 'Bottom left', 'ai-act-image-labels' ) ],
	[ 'bottom-right', __( 'Bottom right', 'ai-act-image-labels' ) ],
];

/**
 * A text with its default as placeholder; saved when the person stops typing.
 * @param root0
 * @param root0.label
 * @param root0.value
 * @param root0.placeholder
 * @param root0.onChange
 * @param root0.disabled
 */
function TextSetting( { label, value, placeholder, onChange, disabled } ) {
	const [ draft, setDraft ] = useDraft( value, onChange );
	return (
		<Field label={ label }>
			<input
				type="text"
				value={ draft }
				placeholder={ placeholder }
				disabled={ disabled }
				onChange={ ( e ) => setDraft( e.target.value ) }
			/>
		</Field>
	);
}

/**
 * The label on a picture, rendered by PHP with the settings as they are on the page.
 * @param root0
 * @param root0.settings
 * @param root0.sample
 */
function Preview( { settings, sample } ) {
	const [ kind, setKind ] = useState( 'generated' );
	const [ html, setHtml ] = useState( null );
	const seq = useRef( 0 );
	useEffect( () => {
		const n = ++seq.current;
		const t = window.setTimeout( () => {
			post( '/preview', { settings, id: sample?.id || 0 } )
				.then( ( r ) => n === seq.current && setHtml( r ) )
				.catch( () => undefined );
		}, 150 );
		return () => window.clearTimeout( t );
	}, [ settings, sample ] );
	return (
		<div className="aal-lpreview">
			<div className="aal-lpreview__bar">
				<b>
					{ __( 'How it looks on the site', 'ai-act-image-labels' ) }
				</b>
				<Seg
					value={ kind }
					label={ __( 'Kind', 'ai-act-image-labels' ) }
					onChange={ setKind }
					options={ [
						{
							value: 'generated',
							label: __( 'AI-generated', 'ai-act-image-labels' ),
						},
						{
							value: 'modified',
							label: __( 'Modified', 'ai-act-image-labels' ),
						},
					] }
				/>
			</div>
			{ html ? <style>{ html.css }</style> : null }
			<figure className="aal-lpreview__fig">
				<span
					className={ `ai-act-label ai-act-label--${ settings.position }` }
				>
					{ sample?.url ? (
						<img src={ sample.url } alt="" />
					) : (
						<span className="aal-lpreview__blank" />
					) }
					{ html ? (
						<span
							className="aal-lpreview__badge"
							// eslint-disable-next-line react/no-danger -- markup rendered by the plugin's PHP.
							dangerouslySetInnerHTML={ { __html: html[ kind ] } }
						/>
					) : null }
				</span>
				{ settings.caption ? (
					<figcaption>
						{ __(
							'Our workshop in autumn',
							'ai-act-image-labels'
						) }
						<span className="ai-act-label__caption">
							{ ' – ' }
							{ kind === 'modified'
								? settings.texts.noticeModified ||
								  __(
										'This image was modified with AI.',
										'ai-act-image-labels'
								  )
								: settings.texts.noticeGenerated ||
								  __(
										'This image was created with AI.',
										'ai-act-image-labels'
								  ) }
						</span>
					</figcaption>
				) : null }
			</figure>
			<div className="aal-lpreview__small">
				<span className="ai-act-label">
					{ sample?.url ? (
						<img src={ sample.url } alt="" width="180" />
					) : (
						<span className="aal-lpreview__blank is-small" />
					) }
					{ html ? (
						<span
							className="aal-lpreview__badge"
							// eslint-disable-next-line react/no-danger -- markup rendered by the plugin's PHP.
							dangerouslySetInnerHTML={ { __html: html.small } }
						/>
					) : null }
				</span>
				<span className="aal-fine">
					{ __(
						'On small images (under 400 px) the plugin uses the round icon instead of the wide one.',
						'ai-act-image-labels'
					) }
				</span>
			</div>
			{ settings.layer ? (
				<span className="aal-fine">
					{ __(
						"Hover over the icon: the second layer shows the notice. Screen readers read it as the icon's name – the image's alt text stays as it is.",
						'ai-act-image-labels'
					) }
				</span>
			) : null }
		</div>
	);
}

/**
 * Darstellung: which icon, where, which words, where the label goes.
 * @param root0
 * @param root0.state
 * @param root0.setState
 */
export default function Look( { state, setState } ) {
	const s = state.settings;
	const can = state.canSettings;
	const [ error, setError ] = useState( null );
	const [ local, setLocal ] = useState( s );
	useEffect( () => setLocal( state.settings ), [ state.settings ] );

	const save = ( patch ) => {
		setLocal( ( l ) => ( {
			...l,
			...patch,
			texts: { ...l.texts, ...( patch.texts || {} ) },
			where: { ...l.where, ...( patch.where || {} ) },
		} ) );
		if ( ! can ) {
			return;
		}
		post( '/settings', { patch } )
			.then( ( next ) => setState( next ) )
			.catch( ( e ) => setError( problem( e ).message ) );
	};

	const pickIcon = () => {
		const frame = window.wp?.media( {
			title: __( 'Choose your own icon', 'ai-act-image-labels' ),
			library: { type: [ 'image' ] },
			multiple: false,
		} );
		if ( ! frame ) {
			return;
		}
		frame.on( 'select', () => {
			const a = frame.state().get( 'selection' ).first().toJSON();
			save( { custom: a.id, icon: 'custom' } );
		} );
		frame.open();
	};

	const [ hex, setHex ] = useDraft(
		local.hex,
		( v ) => save( { hex: v } ),
		400
	);
	const picker = useRef();
	const openPicker = () => {
		const el = picker.current;
		if ( ! el ) {
			return;
		}
		try {
			el.showPicker();
		} catch {
			el.click();
		}
	};

	const d = state.defaults;
	return (
		<div className="aal-split">
			<div className="aal-split__levers">
				{ ! can ? (
					<Notice>
						{ __(
							'Administrators change the appearance. You can still label.',
							'ai-act-image-labels'
						) }
					</Notice>
				) : null }
				{ error ? <Notice tone="error">{ error }</Notice> : null }
				<Section
					title={ __( 'Icon', 'ai-act-image-labels' ) }
					hint={ __(
						"The default is the European Commission's icons – free to use, no attribution.",
						'ai-act-image-labels'
					) }
				>
					<div className="aal-icons">
						{ ICONS.map( ( [ value, name, icon ] ) => (
							<button
								key={ value }
								type="button"
								className={ `aal-opt${
									local.icon === value ? ' is-on' : ''
								}` }
								disabled={ ! can }
								onClick={ () =>
									value === 'custom' && ! local.custom
										? pickIcon()
										: save( { icon: value } )
								}
							>
								<span className={ `aal-opt__pv is-${ value }` }>
									{ value === 'custom' ? (
										state.custom?.url ? (
											<img
												src={ state.custom.url }
												alt=""
											/>
										) : (
											<span className="aal-opt__plus">
												+
											</span>
										)
									) : (
										<EuIcon name={ icon } />
									) }
									{ value === 'basic-text' ? (
										<EuIcon
											name="generated"
											className="aal-opt__open"
										/>
									) : null }
								</span>
								{ name }
							</button>
						) ) }
					</div>
					{ local.icon === 'custom' ? (
						<Button
							size="sm"
							onClick={ pickIcon }
							disabled={ ! can }
						>
							{ __(
								'Choose another icon',
								'ai-act-image-labels'
							) }
						</Button>
					) : null }
					{ local.icon === 'sparkle' || local.icon === 'custom' ? (
						<span className="aal-fine">
							{ __(
								'Your own mark is allowed if it is as clear as the EU icon. The EU icon is the safer choice.',
								'ai-act-image-labels'
							) }
						</span>
					) : null }
				</Section>

				<Card className="aal-col aal-gap16">
					<Line label={ __( 'Color', 'ai-act-image-labels' ) }>
						<Seg
							value={ local.color }
							label={ __( 'Color', 'ai-act-image-labels' ) }
							onChange={ ( v ) => {
								if ( v !== local.color ) {
									save( { color: v } );
								}
								// "Color" opens the browser's colour picker, also when it is chosen already.
								if ( v === 'custom' ) {
									openPicker();
								}
							} }
							options={ COLORS.map( ( [ value, label ] ) => ( {
								value,
								label,
								style:
									value === 'custom' &&
									local.color === 'custom'
										? {
												background: hex,
												color: ink( hex ),
										  }
										: undefined,
							} ) ) }
						/>
						<input
							ref={ picker }
							type="color"
							className="aal-picker"
							tabIndex={ -1 }
							aria-label={ __(
								'Pick a color',
								'ai-act-image-labels'
							) }
							value={ hex }
							onChange={ ( e ) => setHex( e.target.value ) }
						/>
					</Line>
					<Line
						label={ __( 'Transparency', 'ai-act-image-labels' ) }
						hint={ __(
							'Of the shape; the letters stay solid.',
							'ai-act-image-labels'
						) }
					>
						<Seg
							value={ String( local.alpha ) }
							label={ __(
								'Transparency',
								'ai-act-image-labels'
							) }
							onChange={ ( v ) => save( { alpha: Number( v ) } ) }
							options={ ALPHAS.map( ( a ) => ( {
								value: String( a ),
								label: sprintf(
									/* translators: %d: a percentage. */
									__( '%d%%', 'ai-act-image-labels' ),
									a
								),
							} ) ) }
						/>
					</Line>
					<Line label={ __( 'Size', 'ai-act-image-labels' ) }>
						<Seg
							value={ local.size }
							label={ __( 'Size', 'ai-act-image-labels' ) }
							onChange={ ( v ) => save( { size: v } ) }
							options={ [
								{ value: 's', label: 'S' },
								{ value: 'm', label: 'M' },
								{ value: 'l', label: 'L' },
							] }
						/>
					</Line>
					<Line
						label={ __( 'Corner', 'ai-act-image-labels' ) }
						hint={ __(
							'Videos: always at the top, away from the controls.',
							'ai-act-image-labels'
						) }
					>
						<span
							className="aal-corners"
							role="radiogroup"
							aria-label={ __( 'Corner', 'ai-act-image-labels' ) }
						>
							{ POSITIONS.map( ( [ value, label ] ) => (
								<button
									key={ value }
									type="button"
									role="radio"
									aria-checked={
										local.position === value
											? 'true'
											: 'false'
									}
									aria-label={ label }
									title={ label }
									className={ `is-${ value }${
										local.position === value ? ' is-on' : ''
									}` }
									disabled={ ! can }
									onClick={ () =>
										save( { position: value } )
									}
								>
									<i />
								</button>
							) ) }
						</span>
					</Line>
				</Card>

				<Section
					title={ __( 'Words', 'ai-act-image-labels' ) }
					hint={ __(
						"Leave empty: the default in the site's language.",
						'ai-act-image-labels'
					) }
				>
					<Card className="aal-grid2">
						<TextSetting
							label={ __(
								'AI-generated',
								'ai-act-image-labels'
							) }
							value={ local.texts.generated }
							placeholder={ d.generated }
							disabled={ ! can }
							onChange={ ( v ) =>
								save( { texts: { generated: v } } )
							}
						/>
						<TextSetting
							label={ __(
								'Modified with AI',
								'ai-act-image-labels'
							) }
							value={ local.texts.modified }
							placeholder={ d.modified }
							disabled={ ! can }
							onChange={ ( v ) =>
								save( { texts: { modified: v } } )
							}
						/>
						<TextSetting
							label={ __(
								'Notice for AI-generated',
								'ai-act-image-labels'
							) }
							value={ local.texts.noticeGenerated }
							placeholder={ d.noticeGenerated }
							disabled={ ! can }
							onChange={ ( v ) =>
								save( { texts: { noticeGenerated: v } } )
							}
						/>
						<TextSetting
							label={ __(
								'Notice for modified',
								'ai-act-image-labels'
							) }
							value={ local.texts.noticeModified }
							placeholder={ d.noticeModified }
							disabled={ ! can }
							onChange={ ( v ) =>
								save( { texts: { noticeModified: v } } )
							}
						/>
					</Card>
				</Section>

				<Section
					title={ __(
						'Where the label appears',
						'ai-act-image-labels'
					) }
				>
					<Card className="aal-col aal-gap12">
						{ [
							[
								'content',
								__(
									'Images in pages and posts',
									'ai-act-image-labels'
								),
								__(
									'Blocks, galleries, covers, classic editor, HTML',
									'ai-act-image-labels'
								),
							],
							[
								'featured',
								__(
									"Featured images and the theme's images",
									'ai-act-image-labels'
								),
								__(
									'everything that comes through wp_get_attachment_image()',
									'ai-act-image-labels'
								),
							],
							[
								'video',
								__( 'Videos', 'ai-act-image-labels' ),
								__(
									'Video block, cover with video, [video]',
									'ai-act-image-labels'
								),
							],
							[
								'audio',
								__( 'Audio', 'ai-act-image-labels' ),
								__(
									'a line above the player',
									'ai-act-image-labels'
								),
							],
						].map( ( [ key, label, hint ] ) => (
							<Line key={ key } label={ label } hint={ hint }>
								<Switch
									checked={ !! local.where[ key ] }
									label={ label }
									disabled={ ! can }
									onChange={ ( v ) =>
										save( { where: { [ key ]: v } } )
									}
								/>
							</Line>
						) ) }
						<Line
							label={ __(
								'Notice in captions',
								'ai-act-image-labels'
							) }
							hint={ __(
								'Only where there is one already – never in the alt text.',
								'ai-act-image-labels'
							) }
						>
							<Switch
								checked={ local.caption }
								label={ __(
									'Notice in captions',
									'ai-act-image-labels'
								) }
								disabled={ ! can }
								onChange={ ( v ) => save( { caption: v } ) }
							/>
						</Line>
						<Line
							label={ __(
								'Second layer on hover',
								'ai-act-image-labels'
							) }
							hint={ __(
								'The whole notice, with the tool.',
								'ai-act-image-labels'
							) }
						>
							<Switch
								checked={ local.layer }
								label={ __(
									'Second layer on hover',
									'ai-act-image-labels'
								) }
								disabled={ ! can }
								onChange={ ( v ) => save( { layer: v } ) }
							/>
						</Line>
					</Card>
				</Section>

				<Section title={ __( 'On upload', 'ai-act-image-labels' ) }>
					<Card className="aal-col aal-gap12">
						<Line
							label={ __(
								'Check files for traces of AI',
								'ai-act-image-labels'
							) }
							hint={ __(
								'C2PA, IPTC, generation data, file names – on your server.',
								'ai-act-image-labels'
							) }
						>
							<Switch
								checked={ local.detect }
								label={ __(
									'Check files for traces of AI',
									'ai-act-image-labels'
								) }
								disabled={ ! can }
								onChange={ ( v ) => save( { detect: v } ) }
							/>
						</Line>
						<Line
							label={ __(
								'Apply unambiguous traces right away',
								'ai-act-image-labels'
							) }
							hint={ __(
								'If the file itself says it was made with AI, it is labeled right away.',
								'ai-act-image-labels'
							) }
						>
							<Switch
								checked={ local.auto }
								label={ __(
									'Apply unambiguous traces right away',
									'ai-act-image-labels'
								) }
								disabled={ ! can }
								onChange={ ( v ) => save( { auto: v } ) }
							/>
						</Line>
					</Card>
				</Section>
			</div>
			<div className="aal-split__preview">
				<Preview settings={ local } sample={ state.sample } />
			</div>
		</div>
	);
}
