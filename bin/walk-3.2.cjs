/**
 * Sub-PR 3.2.4 live harness — catalog ingest against the REAL Smaily
 * rec engine (MiuMjau tenant), the contract-validation counterpart to the
 * mock-server integration suite (RecEngineCatalogTest).
 *
 * Catalog ingest has no new admin UI — the merchant-visible surface is
 * "save a product, it shows up in the engine". So unlike walk-3.0/3.1
 * (Step-4 React states) this harness is backend-driven: it exercises the
 * real CatalogHookHandler → IngestQueue → IngestFlusher → Client path
 * against the live engine and asserts the engine accepted each batch and
 * honours the dedup contract.
 *
 * This harness CAUGHT the products→items wrapper-key divergence (fix
 * a2ea53c): the mock accepted {products}, the live engine 400s on it and
 * requires {items}. Mock + plugin now align with the live engine.
 *
 * Gated on RECENGINE_LIVE=1. Requires a connected tenant in the wp-env DB
 * (run setup-exchange first). MUST run before any integration-suite run —
 * EnvScrub wipes the smly_rec_* options the connection lives in.
 */
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const PLUGIN_PATH_IN_CONTAINER = '/var/www/html/wp-content/plugins/smaily-connect';

const runDocker = (args) => {
  const joined = args.map((a) => `'${a.replace(/'/g, "'\\''")}'`).join(' ');
  let canDocker = true;
  try { execSync('docker ps', { stdio: 'pipe' }); } catch { canDocker = false; }
  const cmd = canDocker ? `docker ${joined}` : `sg docker -c "docker ${joined}"`;
  return execSync(cmd, { stdio: 'pipe', maxBuffer: 1024 * 1024 * 32 }).toString();
};

const findCliContainer = () => {
  const list = runDocker(['ps', '--filter', 'name=wp-env-', '--filter', 'name=-cli-1', '--format', '{{.Names}}'])
    .split('\n').filter((n) => n && !n.includes('-tests-cli-1'));
  if (list.length === 0) {
    throw new Error('No wp-env cli container found. Start wp-env: npx @wordpress/env start');
  }
  return list[0];
};

const LIVE_PHP = String.raw`<?php
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;

function result( $name, $cond, $detail = '' ) {
	echo 'RESULT ' . $name . ' ' . ( $cond ? 'PASS' : 'FAIL' ) . ' ' . $detail . "\n";
}

// Direct POST (bypasses the plugin Client, which always sends per-item) so
// the W-vs-P diagnostic can control the exact body shape.
function live_post( $url, $auth, $body ) {
	$r = wp_remote_post( $url, array( 'timeout' => 20, 'headers' => $auth, 'body' => wp_json_encode( $body ) ) );
	if ( is_wp_error( $r ) ) {
		return array( 'err' => $r->get_error_message() );
	}
	$decoded = json_decode( (string) wp_remote_retrieve_body( $r ), true );
	return is_array( $decoded ) ? $decoded : array();
}

function live_make_simple( $sku, $price ) {
	$existing = wc_get_product_id_by_sku( $sku );
	if ( $existing ) {
		wp_delete_post( $existing, true );
	}
	$term = term_exists( 'live-test-cat', 'product_cat' );
	if ( ! $term ) {
		$term = wp_insert_term( 'Live Test Cat', 'product_cat', array( 'slug' => 'live-test-cat' ) );
	}
	$p = new WC_Product_Simple();
	$p->set_sku( $sku );
	$p->set_name( 'Live Test ' . $sku );
	$p->set_regular_price( $price );
	$p->set_price( $price );
	$p->set_stock_status( 'instock' );
	if ( is_array( $term ) && isset( $term['term_id'] ) ) {
		$p->set_category_ids( array( (int) $term['term_id'] ) );
	}
	return (int) $p->save();
}

$bootstrap = Bootstrap::instance();
$settings  = $bootstrap->rec_engine_settings();
$queue     = $bootstrap->ingest_queue();
$builder   = $bootstrap->catalog_payload_builder();
$flusher   = $bootstrap->ingest_flusher();
$handler   = new CatalogHookHandler( $queue, $builder, $settings );
$created   = array();

// Determinism: this harness drives the handler explicitly. In wp eval-file
// the Bootstrap WC hooks are already registered, so product create/delete
// during the test would double-enqueue (and cleanup-deletes add noise).
// Silence the registered hooks and start from an empty queue.
global $wpdb;
$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}smly_rec_event_queue" );
remove_all_actions( 'save_post_product' );
remove_all_actions( 'woocommerce_product_set_stock_status' );
remove_all_actions( 'before_delete_post' );

result( 'connected', $settings->is_connected() && strlen( $settings->api_key() ) > 0, 'tenant=' . $settings->tenant_name() );

// --- 1. upsert end-to-end (hook → queue → flusher → engine) --------------
CatalogHookHandler::reset_seen();
$pid       = live_make_simple( 'LIVE-CAT-1', '12.99' );
$created[] = $pid;
$handler->on_save_product( $pid );
$uuid1 = '';
foreach ( $queue->pending( 200 ) as $r ) {
	if ( (string) $r['entity_id'] === (string) $pid && $r['event_type'] === CatalogHookHandler::EVENT_CATALOG_UPSERT ) {
		$uuid1 = (string) $r['event_uuid'];
	}
}
result( 'upsert_enqueued', $uuid1 !== '', 'event_uuid=' . $uuid1 );
$stats = $flusher->flush();
result( 'upsert_flushed_to_engine', $stats['sent'] >= 1 && $stats['failed'] === 0, json_encode( $stats ) );

// --- 2. idempotency on resend (same event_id) ----------------------------
// The deployed engine does NOT implement Layer-2 event_id dedup (the 3.2.4
// probe: a resent valid-UUID event_id re-UPSERTs, returning ok:true rather
// than {"deduplicated":true} — contract §7 / "6/6 sanity" notwithstanding).
// The plugin-relevant guarantee is therefore just that a resend is a SUCCESS
// (2xx, no throw): the flush job marks the row sent and never infinite-
// retries. Idempotency itself holds via Layer-1 natural-key (sku) UPSERT.
$client = $bootstrap->rec_client();
$object = $builder->build( wc_get_product( $pid ), wp_generate_uuid4() );
try {
	$first  = $client->ingest_catalog( array( $object ) );
	$second = $client->ingest_catalog( array( $object ) );
	result( 'resend_first_ok', ! empty( $first['ok'] ) || ! empty( $first['deduplicated'] ), json_encode( $first ) );
	result( 'resend_second_idempotent_success', ! empty( $second['ok'] ) || ! empty( $second['deduplicated'] ), json_encode( $second ) );
} catch ( \Throwable $e ) {
	result( 'resend_first_ok', false, 'EXC ' . $e->getMessage() );
	result( 'resend_second_idempotent_success', false, 'EXC ' . $e->getMessage() );
}

// --- 2b. event_id LOCATION diagnostic (wrapper W vs per-item P) ----------
// History: in 3.2.4 the engine's Zod accepted event_id ONLY at the wrapper
// level — per-item (Variant P, the plugin's design) was silently stripped,
// so Layer-2 dedup never fired. Route A W1 added per-item acceptance on all
// 4 endpoints. Both locations now dedup; this keeps both asserted so a
// regression on either is caught. The plugin sends per-item (P).
$ingest_url = $settings->endpoints()['ingest_catalog'];
$auth       = array( 'Authorization' => 'Bearer ' . $settings->api_key(), 'Content-Type' => 'application/json' );
$diag_obj   = array( 'sku' => 'LIVE-WP', 'name' => 'WP Diag', 'category_path' => 'food/dry', 'price' => 1.00, 'in_stock' => true, 'product_url' => 'https://x.test/wp' );

$w_body = array( 'event_id' => wp_generate_uuid4(), 'items' => array( array_merge( $diag_obj, array( 'sku' => 'LIVE-WP-W' ) ) ) );
live_post( $ingest_url, $auth, $w_body );
$w2 = live_post( $ingest_url, $auth, $w_body );
result( 'dedup_wrapper_event_id_works', ! empty( $w2['deduplicated'] ), json_encode( $w2 ) );

$p_body = array( 'items' => array( array_merge( $diag_obj, array( 'sku' => 'LIVE-WP-P', 'event_id' => wp_generate_uuid4() ) ) ) );
live_post( $ingest_url, $auth, $p_body );
$p2 = live_post( $ingest_url, $auth, $p_body );
result( 'dedup_peritem_event_id_works', ! empty( $p2['deduplicated'] ), json_encode( $p2 ) );

// --- 3. batch of 100 products in one engine call -------------------------
$batch = array();
for ( $i = 1; $i <= 100; $i++ ) {
	$obj                = $object;
	$obj['sku']         = sprintf( 'LIVE-BATCH-%03d', $i );
	// Valid UUID v4 — post-W1 the engine validates per-item event_id as a
	// UUID, and the plugin always sends wp_generate_uuid4.
	$obj['event_id']    = wp_generate_uuid4();
	$obj['name']        = 'Live Batch ' . $i;
	$batch[]            = $obj;
}
try {
	$resp = $client->ingest_catalog( $batch );
	result( 'batch_100_processed', (int) ( $resp['processed'] ?? 0 ) === 100 && empty( $resp['errors'] ), json_encode( array( 'processed' => $resp['processed'] ?? null, 'errors' => count( $resp['errors'] ?? array() ) ) ) );
} catch ( \Throwable $e ) {
	result( 'batch_100_processed', false, 'EXC ' . $e->getMessage() );
}

// --- 4. variable product → distinct event_uuid per variation -------------
CatalogHookHandler::reset_seen();
$var_uuids = array();
try {
	foreach ( array( 'LIVE-VAR-PARENT', 'LIVE-VAR-S', 'LIVE-VAR-L' ) as $sku ) {
		$ex = wc_get_product_id_by_sku( $sku );
		if ( $ex ) {
			wp_delete_post( $ex, true );
		}
	}
	$term   = term_exists( 'live-test-cat', 'product_cat' );
	$parent = new WC_Product_Variable();
	$parent->set_sku( 'LIVE-VAR-PARENT' );
	$parent->set_name( 'Live Variable' );
	if ( is_array( $term ) ) {
		$parent->set_category_ids( array( (int) $term['term_id'] ) );
	}
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'size' );
	$attr->set_options( array( 'S', 'L' ) );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$parent->set_attributes( array( $attr ) );
	$parent_id = (int) $parent->save();
	$created[] = $parent_id;

	foreach ( array( 'S' => 'LIVE-VAR-S', 'L' => 'LIVE-VAR-L' ) as $size => $vsku ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $parent_id );
		$v->set_sku( $vsku );
		$v->set_regular_price( '10.00' );
		$v->set_attributes( array( 'size' => strtolower( $size ) ) );
		$v->save();
	}

	$handler->on_save_product( $parent_id );
	foreach ( $queue->pending( 200 ) as $r ) {
		if ( $r['event_type'] === CatalogHookHandler::EVENT_CATALOG_UPSERT && in_array( (int) $r['entity_id'], wc_get_product( $parent_id )->get_children(), true ) ) {
			$var_uuids[ (string) $r['entity_id'] ] = (string) $r['event_uuid'];
		}
	}
	$distinct = count( $var_uuids ) === 2 && count( array_unique( $var_uuids ) ) === 2;
	result( 'variable_distinct_event_uuids', $distinct, 'uuids=' . json_encode( array_values( $var_uuids ) ) );
	$vstats = $flusher->flush();
	result( 'variable_flushed_to_engine', $vstats['sent'] >= 2 && $vstats['failed'] === 0, json_encode( $vstats ) );
} catch ( \Throwable $e ) {
	result( 'variable_distinct_event_uuids', false, 'EXC ' . $e->getMessage() );
	result( 'variable_flushed_to_engine', false, 'EXC ' . $e->getMessage() );
}

// --- 5. delete → catalog.delete (in_stock=false) -------------------------
CatalogHookHandler::reset_seen();
$handler->on_delete_product( $pid );
$has_delete = false;
foreach ( $queue->pending( 200 ) as $r ) {
	if ( $r['event_type'] === CatalogHookHandler::EVENT_CATALOG_DELETE && (string) $r['entity_id'] === (string) $pid ) {
		$has_delete = true;
	}
}
result( 'delete_enqueued', $has_delete );
$dstats = $flusher->flush();
result( 'delete_flushed_to_engine', $dstats['sent'] >= 1 && $dstats['failed'] === 0, json_encode( $dstats ) );

// --- 6. partial-success: a batch with one deliberately-bad item ----------
// Documents the engine's per-product error behaviour (F3-14 design input):
// does a batch with one bad product return 200 + errors[{index,sku,...}],
// or reject the whole batch with a 4xx?
$ok_item  = array( 'sku' => 'LIVE-PARTIAL-OK', 'name' => 'Partial OK', 'category_path' => 'food/dry', 'price' => 1.00, 'in_stock' => true, 'product_url' => 'https://x.test/ok', 'event_id' => wp_generate_uuid4() );
$bad_item = $ok_item;
$bad_item['sku']      = '';
$bad_item['event_id'] = wp_generate_uuid4();
// Exploratory: the answer (200+errors[] partial vs whole-batch 4xx) IS the
// result for F3-14 — both are valid engine designs, so this records the
// behaviour rather than asserting one.
try {
	$presp    = $client->ingest_catalog( array( $ok_item, $bad_item ) );
	$behavior = ( isset( $presp['errors'] ) && ! empty( $presp['errors'] ) ) ? '200+errors[] (per-item partial)' : '200 (engine accepted bad item)';
	result( 'partial_batch_behavior_documented', true, $behavior . ' ' . json_encode( $presp ) );
} catch ( \Throwable $e ) {
	result( 'partial_batch_behavior_documented', true, 'whole-batch 4xx, all-or-nothing: ' . $e->getMessage() );
}

// --- 7. backward-compat: no event_id (Layer-1 natural-key UPSERT only) ----
// Spec §7: event_id is optional; without it only Layer-1 (sku) UPSERT runs.
// A 400 here would be a third doc↔runtime divergence.
$no_eid = array( 'sku' => 'LIVE-NOEID-1', 'name' => 'No EventId', 'category_path' => 'food/dry', 'price' => 1.00, 'in_stock' => true, 'product_url' => 'https://x.test/noeid' );
try {
	$nresp = $client->ingest_catalog( array( $no_eid ) );
	result( 'no_event_id_layer1_upsert', ! empty( $nresp['ok'] ), json_encode( $nresp ) );
} catch ( \Throwable $e ) {
	result( 'no_event_id_layer1_upsert', false, 'EXC ' . $e->getMessage() );
}

// --- cleanup test products ----------------------------------------------
foreach ( array( 'LIVE-VAR-S', 'LIVE-VAR-L' ) as $sku ) {
	$id = wc_get_product_id_by_sku( $sku );
	if ( $id ) {
		wp_delete_post( $id, true );
	}
}
foreach ( array_unique( $created ) as $id ) {
	wp_delete_post( $id, true );
}
echo "DONE\n";
`;

(async () => {
  if (process.env.RECENGINE_LIVE !== '1') {
    console.log('walk-3.2: RECENGINE_LIVE is not set to 1 — skipping the live engine test.');
    console.log('         Run: RECENGINE_LIVE=1 node bin/walk-3.2.cjs  (after a real setup-exchange).');
    process.exit(0);
  }

  const cli = findCliContainer();
  const tmpName = 'bin/_walk-3.2-tmp.php';
  const hostPath = path.join(__dirname, '..', tmpName);
  fs.writeFileSync(hostPath, LIVE_PHP);

  let out = '';
  try {
    out = runDocker(['exec', cli, 'wp', 'eval-file', `${PLUGIN_PATH_IN_CONTAINER}/${tmpName}`, '--allow-root']);
  } finally {
    fs.unlinkSync(hostPath);
  }

  const results = out.split('\n')
    .filter((l) => l.startsWith('RESULT '))
    .map((l) => {
      const m = l.match(/^RESULT (\S+) (PASS|FAIL) ?(.*)$/);
      return m ? { name: m[1], status: m[2], detail: m[3] } : null;
    })
    .filter(Boolean);

  console.log('\n=== walk-3.2 live catalog-ingest (real MiuMjau engine) ===');
  let failures = 0;
  for (const r of results) {
    console.log(`  ${r.status === 'PASS' ? '✓' : '✗'} ${r.name}${r.detail ? `  ${r.detail}` : ''}`);
    if (r.status !== 'PASS') failures += 1;
  }
  if (!out.includes('DONE')) {
    console.log('  ✗ live script did not finish (no DONE marker). Raw tail:');
    console.log(out.split('\n').slice(-12).join('\n'));
    failures += 1;
  }
  console.log(`\n${failures === 0 ? 'LIVE OK' : `LIVE FAILED (${failures})`} — ${results.length} checks`);
  process.exit(failures === 0 ? 0 : 1);
})().catch((e) => {
  console.error('walk-3.2 harness error:', e.message);
  process.exit(1);
});
