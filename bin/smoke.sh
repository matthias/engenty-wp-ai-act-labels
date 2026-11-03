#!/bin/sh
# Checks the plugin inside a real, served WordPress (bin/test-site.sh makes one):
#   WP_PATH=/path/to/wordpress sh bin/smoke.sh
set -eu
WP="wp --path=${WP_PATH:?WP_PATH is the WordPress folder}"
fail() { echo "FAIL: $1"; exit 1; }

$WP plugin is-active ai-act-image-labels || fail "the plugin is not active"

routes=$($WP eval 'echo implode(" ", array_keys(rest_get_server()->get_routes("ai-act-labels/v1")));')
for r in state media label settings scan review verify preview; do
	echo "$routes" | grep -q "/ai-act-labels/v1/$r" || fail "REST route $r is missing"
done

$WP ai-act-labels scan | grep -q "Checked" || fail "the check did not run"
all=$($WP ai-act-labels list --filter=all --format=csv)
echo "$all" | grep "team-in-der-werkstatt" | grep -q "IPTC" || fail "the IPTC trace was not found"
echo "$all" | grep "kueche-eiche" | grep -q "C2PA" || fail "the C2PA trace was not found"

home=$($WP eval 'echo home_url("/");')
page=$(curl -fsS "$home")
echo "$page" | grep -q 'id="ai-act-labels-inline-css"' || fail "no label styles on the site"
echo "$page" | grep -q 'class="ai-act-host wp-block-cover' || fail "the cover's label is not on the site"
echo "$page" | grep -q 'role="img" aria-label="KI-generiert: ' || fail "the label has no accessible name"

TEAM=$($WP post list --post_type=attachment --name=unser-team --field=ID)
BEFORE=$($WP post meta get "$TEAM" _ai_act_label || true)
$WP ai-act-labels label "$TEAM" --as=generated >/dev/null
page=$(curl -fsS "$home")
echo "$page" | grep -q "data-ai-act-id=\"$TEAM\"" || fail "a newly labelled image does not show its label"
echo "$page" | grep -q 'Unser Team in der Werkstatt<span class="ai-act-label__caption">' || fail "the caption has no notice"
$WP ai-act-labels label "$TEAM" --as="${BEFORE:-clear}" >/dev/null
echo "ok"
