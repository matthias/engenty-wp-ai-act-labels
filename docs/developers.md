# AI Act Image Labels for developers

## Permissions

AI analysis and the on-site check require permission to edit the individual attachment. The site-wide check
requires `manage_options`; automatic labels also respect each attachment's edit permission. WP-CLI scans are
explicit site-wide operations.

## Checks and suggestions

Strong file metadata keeps its suggested kind when the AI assessment disagrees, and the tool flags that
conflict for review. Assessments override weak filename/tool hints; an uncertain assessment leaves a weak hint
without a suggestion. PNG text is limited to 256 KiB per chunk and 1 MiB in total.

HTML media lookup matches the origin, upload directory, extension and resized/original files recorded in
attachment metadata. CDN URLs work when supplied by WordPress' attachment URL filter. Arbitrary external hosts
and guessed resize names do not inherit a local file's label. Bulk Undo sends at most 200 files per request and
retains unfinished files for retry after a failure.

## For themes

Images a theme prints itself (raw `<img>`, CSS backgrounds) do not pass WordPress' image functions.

```php
if ( function_exists( 'ai_act_labels_wrap' ) ) {
	echo ai_act_labels_wrap( $img_html, $attachment_id );      // the icon on the image
	echo esc_html( ai_act_labels_append_notice( $credit, $attachment_id ) ); // „Foto: Anna – Dieses Bild wurde mit KI erzeugt.“
}
```

`ai_act_labels_kind( $id )` returns `generated`, `modified` or `false`; `ai_act_labels_notice( $id )` the
notice. A theme helper that builds images itself can pass its markup through WordPress' own filter, the label
follows: `apply_filters( 'wp_get_attachment_image', $html, $id, $size, false, $attr )`.

Filters: `ai_act_labels_show` (per place), `ai_act_labels_wrap` (the labelled markup), `ai_act_labels_usage`
(places this plugin does not know). Action: `ai_act_labels_changed`.

## The skill

`skills/eu-ai-act-labels/` is an [Agent Skill](https://agentskills.io): the AI Act rules for labelling media
(deepfake test, standard editing, exceptions, the icons and their placement, dates, sources) as of 9 October
2026. The image analysis thinks with it; any agent that reads skills can use the same folder.

## Development

```bash
npm install && composer install
npm run build
WP_PATH=/tmp/aal-wp WP_URL=http://127.0.0.1:8930 sh bin/test-site.sh   # WordPress + SQLite + demo site
PHP_CLI_SERVER_WORKERS=4 wp --path=/tmp/aal-wp server --host=127.0.0.1 --port=8930
WP_PATH=/tmp/aal-wp vendor/bin/phpunit
WP_PATH=/tmp/aal-wp sh bin/smoke.sh   # with the site served
vendor/bin/phpcs && npm run lint:js && npm run test:js
npm run i18n                          # POT, PO, MO, the scripts' JSON
sh bin/zip.sh
```

The demo site logs in with `admin` / `admin`. The screenshots in `docs/screenshots/` and `.wordpress-org/`
are taken from it at 1440×900, 2×.

WP-CLI: `wp ai-act-labels scan | label <id>... --as=generated | list --filter=suggested | detect <id>`.

The EU icons in `assets/icons/eu` are the European Commission's files (June 2026), free to use without
attribution.

GPL-2.0-or-later.
