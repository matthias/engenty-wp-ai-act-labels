import { __, _x } from '@wordpress/i18n';
import { boot } from './api';
import { EuIcon, Promo } from './parts';
import { Button, Card, Icon, Section } from './ui';

/** Typical files on a business website and what they need. */
const CASES = [
	[
		__(
			'Photorealistic AI “team photo”, customer portrait, testimonial',
			'ai-act-image-labels'
		),
		'generated',
		'required',
	],
	[
		__(
			'Real photo, background or person replaced or added with AI',
			'ai-act-image-labels'
		),
		'modified',
		'required',
	],
	[
		__(
			'AI product shot that looks like a real photo of the product',
			'ai-act-image-labels'
		),
		'generated',
		'required',
	],
	[
		__(
			'AI image of a real building, place or event',
			'ai-act-image-labels'
		),
		'generated',
		'required',
	],
	[
		__(
			'Recognizable illustration, comic, 3D graphic, abstract background',
			'ai-act-image-labels'
		),
		'generated',
		'voluntary',
	],
	[
		__(
			'Real photo, only color, light, noise or crop with an AI tool',
			'ai-act-image-labels'
		),
		'none',
		'none',
	],
	[
		__( 'Camera or stock photo without AI', 'ai-act-image-labels' ),
		'none',
		'none',
	],
];

const DUTY = {
	required: [ 'rose', __( 'required', 'ai-act-image-labels' ) ],
	voluntary: [ 'amber', __( 'voluntary', 'ai-act-image-labels' ) ],
	none: [ 'moss', __( 'no label', 'ai-act-image-labels' ) ],
};

const SOURCES = [
	[
		__(
			'Questions and answers on Article 50 (European Commission)',
			'ai-act-image-labels'
		),
		'https://digital-strategy.ec.europa.eu/en/faqs/transparency-obligations-under-article-50-ai-act',
	],
	[
		__(
			'Code of Practice on labeling AI-generated content',
			'ai-act-image-labels'
		),
		'https://digital-strategy.ec.europa.eu/en/policies/code-practice-ai-generated-content',
	],
	[
		__( 'The EU icons to download', 'ai-act-image-labels' ),
		'https://digital-strategy.ec.europa.eu/en/policies/eu-icons-labelling-ai-generated-content',
	],
	[
		__(
			'AI Act in the Official Journal (Regulation (EU) 2024/1689)',
			'ai-act-image-labels'
		),
		'https://eur-lex.europa.eu/eli/reg/2024/1689/oj',
	],
	[
		__( 'AI Act Service Desk', 'ai-act-image-labels' ),
		'https://ai-act-service-desk.ec.europa.eu/',
	],
];

/**
 * Wissen: what Article 50 asks of a website, in plain words – the same knowledge the assistant has.
 * @param root0
 * @param root0.state
 */
export default function Knowledge( { state } ) {
	return (
		<>
			<div className="aal-khero">
				<div>
					<span className="aal-kick">
						{ __( 'AI Act, Article 50', 'ai-act-image-labels' ) }
					</span>
					<h1>
						{ __(
							'Since 2 August 2026, what AI made has to be visible.',
							'ai-act-image-labels'
						) }
					</h1>
					<p>
						{ __(
							"A business that publishes images, videos or recordings that look real but were created or modified with AI has to label them – visibly, at the latest at first sight. The duty is yours as the site owner, not the AI tool maker's.",
							'ai-act-image-labels'
						) }
					</p>
				</div>
				<div className="aal-khero__icons">
					<EuIcon name="generated" />
					<EuIcon name="modified" />
					<EuIcon name="basic" />
				</div>
			</div>

			<div className="aal-kgrid">
				<Card className="aal-kcard">
					<span className="aal-kcard__ic is-rose">
						<Icon name="warn" size={ 16 } />
					</span>
					<h2>
						{ __( 'You have to label', 'ai-act-image-labels' ) }
					</h2>
					<ul>
						<li>
							{ __(
								'Deepfakes: AI images, videos, audio that show real or plausible people, things, places or events and look real – also an invented but photorealistic person.',
								'ai-act-image-labels'
							) }
						</li>
						<li>
							{ __(
								'AI texts that inform the public on matters of public interest – unless a person reviewed them and takes responsibility.',
								'ai-act-image-labels'
							) }
						</li>
						<li>
							{ __(
								'Whether you meant to deceive does not matter. What your audience believes counts.',
								'ai-act-image-labels'
							) }
						</li>
					</ul>
				</Card>
				<Card className="aal-kcard">
					<span className="aal-kcard__ic is-moss">
						<Icon name="check" size={ 16 } />
					</span>
					<h2>
						{ __( 'No label needed for', 'ai-act-image-labels' ) }
					</h2>
					<ul>
						<li>
							{ __(
								'Ordinary editing: crop, light, color, noise, dust, upscaling – also with AI tools.',
								'ai-act-image-labels'
							) }
						</li>
						<li>
							{ __(
								'Obviously artificial images (illustration, comic, abstract): voluntary, but good for trust.',
								'ai-act-image-labels'
							) }
						</li>
						<li>
							{ __(
								'Content created before 2 August 2026 – nothing applies retroactively.',
								'ai-act-image-labels'
							) }
						</li>
						<li>
							{ __(
								'Art, satire, fiction: yes, but so that the work does not suffer (e.g. in the caption).',
								'ai-act-image-labels'
							) }
						</li>
					</ul>
				</Card>
				<Card className="aal-kcard">
					<span className="aal-kcard__ic">
						<Icon name="eye" size={ 16 } />
					</span>
					<h2>
						{ __( 'Where the label goes', 'ai-act-image-labels' ) }
					</h2>
					<ul>
						<li>
							{ __(
								'On or right at the content, visible without a click – not only in the imprint.',
								'ai-act-image-labels'
							) }
						</li>
						<li>
							{ __(
								"Plain words: “AI-generated”, “Modified with AI”. For screen readers as the icon's name, never instead of the alt text.",
								'ai-act-image-labels'
							) }
						</li>
						<li>
							{ __(
								'Videos: in a corner the whole time. Audio: at the player and in the artwork.',
								'ai-act-image-labels'
							) }
						</li>
						<li>
							{ __(
								"The AI tool's watermark or metadata does not count for you.",
								'ai-act-image-labels'
							) }
						</li>
					</ul>
				</Card>
			</div>

			<Section
				title={ __( "The EU's icons", 'ai-act-image-labels' ) }
				hint={ __(
					'From the Commission since June 2026, free to use, no attribution. Optional – an equivalent label is allowed too.',
					'ai-act-image-labels'
				) }
			>
				<Card className="aal-kicons">
					<div>
						<EuIcon name="generated" />
						<b>{ __( 'AI GENERATED', 'ai-act-image-labels' ) }</b>
						<p>
							{ __(
								'Fully created with AI, no human-made parts.',
								'ai-act-image-labels'
							) }
						</p>
					</div>
					<div>
						<EuIcon name="modified" />
						<b>{ __( 'AI MODIFIED', 'ai-act-image-labels' ) }</b>
						<p>
							{ __(
								'Real photo, video or recording, partly modified with AI.',
								'ai-act-image-labels'
							) }
						</p>
					</div>
					<div>
						<EuIcon name="basic" />
						<b>
							{ _x(
								'AI',
								'name of the EU basic icon',
								'ai-act-image-labels'
							) }
						</b>
						<p>
							{ __(
								'The basic icon: with your own text, with a second layer or where space is short.',
								'ai-act-image-labels'
							) }
						</p>
					</div>
				</Card>
			</Section>

			<Section title={ __( 'Typical cases', 'ai-act-image-labels' ) }>
				<table className="aal-table">
					<thead>
						<tr>
							<th>{ __( 'File', 'ai-act-image-labels' ) }</th>
							<th>{ __( 'Kind', 'ai-act-image-labels' ) }</th>
							<th>{ __( 'Label', 'ai-act-image-labels' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ CASES.map( ( [ text, kind, duty ] ) => (
							<tr key={ text }>
								<td>{ text }</td>
								<td>
									{ kind === 'none'
										? '–'
										: kind === 'modified'
										? __(
												'Modified with AI',
												'ai-act-image-labels'
										  )
										: __(
												'AI-generated',
												'ai-act-image-labels'
										  ) }
								</td>
								<td>
									<span
										className={ `aal-chip aal-chip--${ DUTY[ duty ][ 0 ] }` }
									>
										{ DUTY[ duty ][ 1 ] }
									</span>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</Section>

			<div className="aal-kgrid aal-kgrid--2">
				<Section
					title={ __(
						'How the plugin recognizes AI',
						'ai-act-image-labels'
					) }
				>
					<Card className="aal-prose">
						<p>
							{ __(
								'Many AI tools write into the file that it was made with AI: Content Credentials (C2PA) from ChatGPT, Gemini, Firefly or Photoshop, the IPTC value “trainedAlgorithmicMedia”, the generation data of Stable Diffusion and ComfyUI. The plugin reads them on upload and with every check – on your server, nothing leaves it.',
								'ai-act-image-labels'
							) }
						</p>
						<p>
							{ __(
								'A missing trace means nothing: screenshots, exports and image optimizers remove them. That is why “Used, not decided” shows everything visible on your site that has no decision yet. Only you know for sure how an image was made.',
								'ai-act-image-labels'
							) }
						</p>
					</Card>
				</Section>
				<Section
					title={ __(
						'This knowledge as a skill',
						'ai-act-image-labels'
					) }
				>
					<Card className="aal-prose">
						<p>
							{ __(
								'The image analysis thinks with a skill: a guide to the AI Act with sources, in the plugin under skills/eu-ai-act-labels. You can give the same files to any AI agent that reads Agent Skills – Claude, Cursor, Codex and others.',
								'ai-act-image-labels'
							) }
						</p>
						<span className="aal-row-inline">
							<Button
								size="sm"
								href={ boot.skill }
								external
								icon="book"
							>
								{ __( 'View SKILL.md', 'ai-act-image-labels' ) }
							</Button>
						</span>
					</Card>
				</Section>
			</div>

			<Section
				title={ __( 'Sources', 'ai-act-image-labels' ) }
				hint={ __(
					'As of 9 October 2026. This is help, not legal advice.',
					'ai-act-image-labels'
				) }
			>
				<Card pad0>
					{ SOURCES.map( ( [ name, url ] ) => (
						<div className="aal-row" key={ url }>
							<a
								className="aal-row__main"
								href={ url }
								target="_blank"
								rel="noreferrer"
							>
								<span className="aal-row__t">
									<b>{ name }</b>
									<span>
										{
											url
												.replace( /^https:\/\//, '' )
												.split( '/' )[ 0 ]
										}
									</span>
								</span>
								<Icon name="external" size={ 14 } />
							</a>
						</div>
					) ) }
				</Card>
			</Section>
			<Promo promo={ state.promo } />
		</>
	);
}
