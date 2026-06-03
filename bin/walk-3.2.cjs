/**
 * Sub-PR 3.2.4 live harness — catalog ingest against the REAL Smaily
 * rec engine (MiuMjau tenant), the contract-validation counterpart to the
 * mock-server integration suite (RecEngineCatalogTest).
 *
 * Catalog ingest has no new admin UI — the merchant-visible surface is
 * "save a product, it shows up in the engine". So unlike walk-3.0/3.1
 * (Step-4 React states) this harness is backend-driven: it exercises the
 * real CatalogHookHandler → IngestQueue → IngestFlusher → Client path
 * against the live engine and asserts the engine accepted each batch
 * (HTTP 2xx → row marked sent) and honours the dedup contract.
 *
 * Gated on RECENGINE_LIVE=1 so a normal run never touches the real engine.
 * Requires a connected tenant in the wp-env DB (run the setup-exchange
 * first; the api_key is read from smly_rec_api_key).
 *
 * The plugin code is mounted into the container, so we drop a one-shot PHP
 * file into the plugin dir and run it through `wp eval-file` (full WP + WC
 * boot). It prints `RESULT <name> PASS|FAIL <detail>` lines we parse here.
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
  return execSync(cmd, { stdio: 'pipe', maxBuffer: 1024 * 1024 * 16 }).toString();
};

const findCliContainer = () => {
  const list = runDocker(['ps', '--filter', 'name=wp-env-', '--filter', 'name=-cli-1', '--format', '{{.Names}}'])
    .split('\n').filter((n) => n && !n.includes('-tests-cli-1'));
  if (list.length === 0) {
    throw new Error('No wp-env cli container found. Start wp-env: npx @wordpress/env start');
  }
  return list[0];
};

// One-shot PHP, run through the real WP+WC bootstrap. Catalog flush builds
// a Client from the stored (real) api_key and hits the live engine.
const LIVE_PHP = String.raw`<?php
use Smaily\Connect\Bootstrap;
use Smaily\Connect\Integrations\WooCommerce\CatalogHookHandler;

function result( $name, $cond, $detail = '' ) {
	echo 'RESULT ' . $name . ' ' . ( $cond ? 'PASS' : 'FAIL' ) . ' ' . $detail . "\n";
}

function live_make_product( $sku, $price ) {
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

result( 'connected', $settings->is_connected() && strlen( $settings->api_key() ) > 0, 'tenant=' . $settings->tenant_name() );

$created = array();

// --- Scenario 1: upsert end-to-end against the live engine ---------------
CatalogHookHandler::reset_seen();
$pid       = live_make_product( 'LIVE-CAT-1', '12.99' );
$created[] = $pid;
$handler->on_save_product( $pid );

$uuid1 = '';
foreach ( $queue->pending( 50 ) as $r ) {
	if ( (string) $r['entity_id'] === (string) $pid && $r['event_type'] === CatalogHookHandler::EVENT_CATALOG_UPSERT ) {
		$uuid1 = (string) $r['event_uuid'];
	}
}
result( 'upsert_enqueued', $uuid1 !== '', 'event_uuid=' . $uuid1 );

$stats = $flusher->flush();
result( 'upsert_flushed_to_engine', $stats['sent'] >= 1 && $stats['failed'] === 0, json_encode( $stats ) );

$still_pending = false;
foreach ( $queue->pending( 50 ) as $r ) {
	if ( (string) $r['event_uuid'] === $uuid1 ) {
		$still_pending = true;
	}
}
result( 'upsert_row_sent_not_retried', ! $still_pending );

// --- Scenario 2: dedup contract — resend the SAME event_id ---------------
$client = $bootstrap->rec_client();
$object = $builder->build( wc_get_product( $pid ), 'live-dedup-uuid-0001' );
try {
	$first  = $client->ingest_catalog( array( $object ) );
	$second = $client->ingest_catalog( array( $object ) );
	result( 'dedup_first_processed', empty( $first['deduplicated'] ), json_encode( $first ) );
	result( 'dedup_second_deduplicated', ! empty( $second['deduplicated'] ), json_encode( $second ) );
} catch ( \Throwable $e ) {
	result( 'dedup_first_processed', false, 'EXC ' . $e->getMessage() );
	result( 'dedup_second_deduplicated', false, 'EXC ' . $e->getMessage() );
}

// --- Scenario 3: batch of multiple products in one engine call -----------
CatalogHookHandler::reset_seen();
$pid2      = live_make_product( 'LIVE-CAT-2', '5.50' );
$pid3      = live_make_product( 'LIVE-CAT-3', '7.25' );
$created[] = $pid2;
$created[] = $pid3;
$handler->on_save_product( $pid2 );
$handler->on_save_product( $pid3 );
$stats2 = $flusher->flush();
result( 'batch_multi_product_sent', $stats2['sent'] >= 2 && $stats2['failed'] === 0, json_encode( $stats2 ) );

// --- Scenario 4: delete → catalog.delete (in_stock=false) ----------------
CatalogHookHandler::reset_seen();
$handler->on_delete_product( $pid2 );
$has_delete = false;
foreach ( $queue->pending( 50 ) as $r ) {
	if ( $r['event_type'] === CatalogHookHandler::EVENT_CATALOG_DELETE && (string) $r['entity_id'] === (string) $pid2 ) {
		$has_delete = true;
	}
}
result( 'delete_enqueued', $has_delete );
$stats3 = $flusher->flush();
result( 'delete_flushed_to_engine', $stats3['sent'] >= 1 && $stats3['failed'] === 0, json_encode( $stats3 ) );

// --- Cleanup test products ----------------------------------------------
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

  const lines = out.split('\n');
  const results = lines
    .filter((l) => l.startsWith('RESULT '))
    .map((l) => {
      const m = l.match(/^RESULT (\S+) (PASS|FAIL) ?(.*)$/);
      return m ? { name: m[1], status: m[2], detail: m[3] } : null;
    })
    .filter(Boolean);

  console.log('\n=== walk-3.2 live catalog-ingest (real MiuMjau engine) ===');
  let failures = 0;
  for (const r of results) {
    const mark = r.status === 'PASS' ? '✓' : '✗';
    console.log(`  ${mark} ${r.name}${r.detail ? `  ${r.detail}` : ''}`);
    if (r.status !== 'PASS') failures += 1;
  }

  if (!out.includes('DONE')) {
    console.log('  ✗ live script did not finish (no DONE marker). Raw output:');
    console.log(out);
    failures += 1;
  }

  console.log(`\n${failures === 0 ? 'LIVE OK' : `LIVE FAILED (${failures})`} — ${results.length} checks`);
  process.exit(failures === 0 ? 0 : 1);
})().catch((e) => {
  console.error('walk-3.2 harness error:', e.message);
  process.exit(1);
});
