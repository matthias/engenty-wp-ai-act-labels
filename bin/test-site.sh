#!/bin/sh
# A WordPress with SQLite, the plugin and a demo site (Tischlerei Huber) with media that carry traces of
# AI and media that do not, used as featured images, in blocks, a gallery, a cover and an HTML block:
#   WP_PATH=/tmp/wp WP_URL=http://127.0.0.1:8930 sh bin/test-site.sh
# Then: PHP_CLI_SERVER_WORKERS=4 wp --path=$WP_PATH server --host=127.0.0.1 --port=8930
set -eu
WP_PATH=${WP_PATH:?WP_PATH is the folder for WordPress}
WP_URL=${WP_URL:-http://127.0.0.1:8930}
PLUGIN=$(cd "$(dirname "$0")/.." && pwd)
WP="wp --path=$WP_PATH"
mkdir -p "$WP_PATH"
[ -f "$WP_PATH/wp-load.php" ] || $WP core download --quiet
if [ ! -d "$WP_PATH/wp-content/plugins/sqlite-database-integration" ]; then
	TMP=$(mktemp -d)
	curl -fsSL -o "$TMP/sqlite.zip" https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip
	unzip -q "$TMP/sqlite.zip" -d "$WP_PATH/wp-content/plugins" && rm -rf "$TMP"
fi
sed "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$WP_PATH/wp-content/plugins/sqlite-database-integration#; s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" "$WP_PATH/wp-content/plugins/sqlite-database-integration/db.copy" > "$WP_PATH/wp-content/db.php"
[ -f "$WP_PATH/wp-config.php" ] || $WP config create --dbname=wp --dbuser=x --dbpass=x --skip-check --quiet
$WP core is-installed 2>/dev/null || $WP core install --url="$WP_URL" --title="Tischlerei Huber" --admin_user=admin --admin_password=admin --admin_email=a@example.com --skip-email --quiet
$WP language core install de_DE --activate --quiet || true
$WP rewrite structure '/%postname%/' --quiet
ln -sfn "$PLUGIN" "$WP_PATH/wp-content/plugins/ai-act-image-labels"
$WP plugin activate ai-act-image-labels --quiet
if ! $WP post list --post_type=page --name=start --field=ID | grep -q .; then
	$WP eval-file "$PLUGIN/tests/fixtures/demo-content.php"
	# One label from the start, so the site shows one.
	HEADER=$($WP post list --post_type=attachment --name=werkstatt-am-abend --field=ID)
	$WP ai-act-labels label "$HEADER" --as=generated --tool="Stable Diffusion"
fi
echo "ready: $WP_URL"
