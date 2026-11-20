<?php
// existing-checked: tests/slot-query-test.php - it covers the capacity SQL; these cover saveFields, location ids and the displayed date.
// Run: php tests/checkout-slot-check.php
// Repro for prenejo. 
namespace {
define('ABSPATH', '/');
define('PICKUP_DIR', dirname(__DIR__) . '/');
date_default_timezone_set('UTC'); // WordPress always runs PHP in UTC (wp-settings.php).
$GLOBALS['opt'] = [];
$GLOBALS['notices'] = [];
function get_option($k, $d = false) { return $GLOBALS['opt'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['opt'][$k] = $v; }
function wp_timezone() { return new DateTimeZone('America/New_York'); }
// Core wp_date(): formats a UTC timestamp in the site timezone.
function wp_date($f, $ts) { return (new DateTimeImmutable('@' . $ts))->setTimezone(wp_timezone())->format($f); }
function get_current_blog_id() { return 1; }
function apply_filters($h, $v) { return $v; }
function __($s) { return $s; }
function esc_html_e($s) { echo $s; }
function esc_html__($s) { return $s; }
function esc_attr_e($s) { echo $s; }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function checked($a, $b) { return ''; }
// Mirror core: _sanitize_text_fields() strips percent-encoded octets (formatting.php),
// sanitize_title_with_dashes() percent-encodes non-ASCII bytes and lowercases.
function sanitize_text_field($s) { $s = strip_tags((string) $s); while (preg_match('/%[a-f0-9]{2}/i', $s)) { $s = preg_replace('/%[a-f0-9]{2}/i', '', $s); } return trim(preg_replace('/[\r\n\t ]+/', ' ', $s)); }
function sanitize_title($s) { $s = preg_replace_callback('/[\x80-\xff]/', fn ($m) => strtolower(rawurlencode($m[0])), (string) $s); return trim(preg_replace('/[^a-z0-9%]+/', '-', strtolower($s)), '-'); }
function wp_unslash($v) { return $v; }
function wp_verify_nonce($n, $a) { return 'good' === $n; }
function wc_add_notice($m, $t) { $GLOBALS['notices'][] = $m; }
function wc_get_order_types() { return ['shop_order']; }
class FakeSession { public array $d = []; function get($k) { return $this->d[$k] ?? null; } function set($k, $v) { $this->d[$k] = $v; } }
class FakeWC { public $session; }
$GLOBALS['wc'] = new FakeWC(); $GLOBALS['wc']->session = new FakeSession();
function WC() { return $GLOBALS['wc']; }
class FakeDb { public $postmeta = 'pm'; public $posts = 'p'; public $prefix = 'wp_'; public array $rows = [];
    function prepare($q, ...$a) { return $q; } function get_results($q, $o) { return $this->rows; } }
$GLOBALS['wpdb'] = new FakeDb();
define('ARRAY_A', 'ARRAY_A');
class WC_Order { public array $meta = []; function update_meta_data($k, $v) { $this->meta[$k] = $v; } }
foreach (['src/Contract/HasHooks.php', 'src/Support/SettingsStore.php', 'src/Service/SlotCalculator.php', 'src/Service/CheckoutFields.php', 'src/Service/OrderDisplay.php'] as $f) {
    require PICKUP_DIR . $f;
}
$fail = 0;

// 1. OrderDisplay::formatDate on a shop west of UTC.
$od = new Pickup\Service\OrderDisplay(new Pickup\Support\SettingsStore());
$m = new ReflectionMethod($od, 'formatDate');
$GLOBALS['opt']['date_format'] = 'Y-m-d';
$shown = $m->invoke($od, '2026-11-21');
echo "booked 2026-11-21, shown as $shown\n"; $fail += '2026-11-21' === $shown ? 0 : 1;

// 2. Settings save keeps a location's id, so bookings stay counted against it.
$GLOBALS['opt']['pickup_settings'] = ['locations' => [['id' => 'main', 'name' => '', 'address' => '', 'enabled' => true]]];
require PICKUP_DIR . 'src/Admin/ProUpsell.php';
require PICKUP_DIR . 'src/Admin/Settings.php';
$store = new Pickup\Support\SettingsStore();
$settings = new Pickup\Admin\Settings($store);
ob_start();
$row = $store->locations()[0];
(new ReflectionMethod($settings, 'locationRow'))->invoke($settings, 0, $row);
$html = ob_get_clean();
// What the browser posts back for that row.
preg_match_all('/name="locations\[0\]\[(\w+)\]" value="([^"]*)"/', $html, $mm, PREG_SET_ORDER);
$posted = [];
foreach ($mm as $x) { $posted[$x[1]] = $x[2]; }
$posted['enabled'] = '1';
$clean = (new ReflectionMethod($settings, 'sanitizeLocations'))->invoke($settings, [$posted]);
echo "seed location id 'main' saved as '{$clean[0]['id']}'\n"; $fail += 'main' === $clean[0]['id'] ? 0 : 1;
$posted['name'] = 'Downtown';
$clean = (new ReflectionMethod($settings, 'sanitizeLocations'))->invoke($settings, [$posted]);
echo "renamed location saved as '{$clean[0]['id']}'\n"; $fail += 'main' === $clean[0]['id'] ? 0 : 1;
$clean = (new ReflectionMethod($settings, 'sanitizeLocations'))->invoke($settings, [['name' => 'New Spot', 'id' => '']]);
echo "new location without id gets '{$clean[0]['id']}'\n"; $fail += 'new-spot' === $clean[0]['id'] ? 0 : 1;

$clean = (new ReflectionMethod($settings, 'sanitizeLocations'))->invoke($settings, [['name' => "\u{041C}\u{0430}\u{0433}\u{0430}\u{0437}\u{0438}\u{043D}", 'id' => '']]);
$posted = sanitize_text_field($clean[0]['id']); // what validateFields() reads back from the checkout form
echo "non-Latin location id '{$clean[0]['id']}' reads back as '$posted'\n"; $fail += $posted === $clean[0]['id'] && '' !== $posted ? 0 : 1;

// 3. Checkout: the slot is full; the session still says flat_rate when woocommerce_checkout_process
//    fires (class-wc-checkout.php: checkout_process at 1303, update_session at 1310).
$GLOBALS['opt']['pickup_settings'] = ['capacity' => 1, 'lead_hours' => 0, 'windows' => array_fill(1, 7, [['start' => '00:00', 'end' => '23:59']]),
    'locations' => [['id' => 'main', 'name' => 'Main', 'address' => '', 'enabled' => true]]];
$date = (new DateTimeImmutable('+2 days', wp_timezone()))->format('Y-m-d');
$GLOBALS['wpdb']->rows = [['pickup_date' => $date, 'pickup_slot' => '10:00', 'booked' => 1]];
$store = new Pickup\Support\SettingsStore();
$calc = new Pickup\Service\SlotCalculator($store);
$cf = new Pickup\Service\CheckoutFields($store, $calc);
$_POST = ['_pickup_nonce' => 'good', 'pickup_location' => 'main', 'pickup_date' => $date, 'pickup_slot' => '10:00'];
WC()->session->set('chosen_shipping_methods', ['flat_rate:1']);
$cf->validateFields();
WC()->session->set('chosen_shipping_methods', ['local_pickup:2']); // update_session() copies the posted method
$order = new WC_Order();
$blocked = false;
try { $cf->saveFields($order, []); } catch (\Exception $e) { $blocked = true; }
echo 'full slot stored on order: ', var_export(isset($order->meta['_pickup_slot']) && ! $blocked, true), ', blocked: ', var_export($blocked, true), "\n";
$fail += $blocked ? 0 : 1;

// 3a. Same switch, without a nonce or with a location that does not exist: still refused.
foreach ([['_pickup_nonce' => ''], ['pickup_location' => 'nowhere']] as $override) {
    $_POST = array_merge(['_pickup_nonce' => 'good', 'pickup_location' => 'main', 'pickup_date' => $date, 'pickup_slot' => '10:00'], $override);
    WC()->session->set('chosen_shipping_methods', ['flat_rate:1']);
    $cf->validateFields();
    WC()->session->set('chosen_shipping_methods', ['local_pickup:2']);
    $blocked = false;
    try { $cf->saveFields(new WC_Order(), []); } catch (\Exception $e) { $blocked = true; }
    echo 'switch with ' . json_encode($override) . ' blocked: ', var_export($blocked, true), "\n"; $fail += $blocked ? 0 : 1;
}
$_POST = ['_pickup_nonce' => 'good', 'pickup_location' => 'main', 'pickup_date' => $date, 'pickup_slot' => '10:00'];

// 3b. Normal flow, a free slot: validate with pickup in the session, then save.
$_POST['pickup_slot'] = '11:00';
$GLOBALS['notices'] = [];
$cf->validateFields();
$order = new WC_Order();
try { $cf->saveFields($order, []); } catch (\Exception $e) { echo 'unexpected: ', $e->getMessage(), "\n"; }
echo 'normal booking stored: ', var_export(($order->meta['_pickup_slot'] ?? '') === '11:00', true), "\n";
$fail += ($order->meta['_pickup_slot'] ?? '') === '11:00' && [] === $GLOBALS['notices'] ? 0 : 1;

echo $fail ? "FAIL ($fail)\n" : "PASS\n";
exit($fail ? 1 : 0);
}
