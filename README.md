# AI Act Image Labels

A WordPress plugin that marks AI-generated and AI-modified media the way the EU AI Act asks (Article 50,
applies since 2 August 2026) – right in the media library, with the EU's official icons on the site.

- **Media library:** every image, video and recording gets a decision – *KI-generiert*, *Mit KI verändert*
  or *Kein Hinweis nötig* – in the details (modal and edit screen), as bulk actions and with a filter and
  a column in the list. Labelled tiles carry the round EU icon.
- **On the site:** the EU icon (*AI GENERATED*, *AI MODIFIED* or the round *AI*) in a corner of the image,
  with the notice as its accessible name and, on hover, as a second layer; the notice behind captions that
  are shown anyway – never in the alt text. Featured images, blocks, galleries, covers, media & text,
  `[caption]`, video and audio get it by themselves; images in HTML blocks and page builders are found by
  their file.
- **Traces of AI:** on upload and on every check the plugin reads the file's own data – C2PA Content
  Credentials, the IPTC digital source type, Stable Diffusion/ComfyUI generation data, EXIF tools, the file
  names AI tools use – and suggests a label. Nothing leaves the server.
- **Where it is used:** the check reads every page, post, template and reusable block and lists where each
  file is used; *Auf der Website prüfen* opens those pages and shows whether the label really appears.
- **Bild analysieren (KI):** looks at a file (the picture, its traces, where it is used) and says whether a
  label is required, voluntary or not needed – per file, for a list, and in the media library's box. It uses
  the AI provider set up under *Einstellungen › Konnektoren* (WordPress 7.0+); without one, the button shows
  the way there.
- **Configurable:** icon (EU icon by kind, round icon, round icon that opens on hover, own icon, sparkle),
  colour (dark, light, picked per image, own colour), transparency 0–75 %, size, corner, words, where the
  label appears.
- **Card from engenty:** the words of the card at the end of the tool ship with the plugin
  (`includes/class-promo.php`) and change with a release; the card makes no request.

The tool lives under **Media › AI labels** (German: *Medien › KI-Kennzeichnung*), tabs *Review*,
*Appearance*, *Knowledge*. The source strings are English; German (de_DE, also read by de_AT and de_CH)
and British English ship in `languages/`.

## The skill

`skills/eu-ai-act-labels/` is an [Agent Skill](https://agentskills.io): the AI Act rules for labelling
media (deepfake test, standard editing, exceptions, the icons and their placement, dates, sources) as of
9 October 2026. The image analysis thinks with it; any agent that reads skills can use the same folder.

## For themes

Images a theme prints itself (raw `<img>`, CSS backgrounds) do not pass WordPress' image functions.

```php
if ( function_exists( 'ai_act_labels_wrap' ) ) {
	echo ai_act_labels_wrap( $img_html, $attachment_id );      // the icon on the image
	echo esc_html( ai_act_labels_append_notice( $credit, $attachment_id ) ); // „Foto: Anna – Dieses Bild wurde mit KI erzeugt.“
}
```

`ai_act_labels_kind( $id )` returns `generated`, `modified` or `false`; `ai_act_labels_notice( $id )` the
notice. A theme helper that builds images itself can pass its markup through WordPress' own filter, the
label follows: `apply_filters( 'wp_get_attachment_image', $html, $id, $size, false, $attr )`.

Filters: `ai_act_labels_show` (per place), `ai_act_labels_wrap` (the labelled markup),
`ai_act_labels_usage` (places this plugin does not know). Action: `ai_act_labels_changed`.

## Development

```bash
npm install && composer install
npm run build
WP_PATH=/tmp/aal-wp WP_URL=http://127.0.0.1:8930 sh bin/test-site.sh   # WordPress + SQLite + demo site
PHP_CLI_SERVER_WORKERS=4 wp --path=/tmp/aal-wp server --host=127.0.0.1 --port=8930
WP_PATH=/tmp/aal-wp vendor/bin/phpunit
WP_PATH=/tmp/aal-wp sh bin/smoke.sh   # with the site served
vendor/bin/phpcs && npm run lint:js
npm run i18n                          # POT, PO, MO, the scripts' JSON
sh bin/zip.sh
```

WP-CLI: `wp ai-act-labels scan | label <id>... --as=generated | list --filter=suggested | detect <id>`.

The EU icons in `assets/icons/eu` are the European Commission's files (June 2026), free to use without
attribution. This plugin helps with labelling; it is not legal advice.

GPL-2.0-or-later.
