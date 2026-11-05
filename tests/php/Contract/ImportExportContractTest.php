<?php
namespace WPFinet\Tests\Contract;

use FinetTroubleshoot\Export\ExportManager;
use FinetTroubleshoot\Import\ImportManager;
use FinetTroubleshoot\Validation\FlowValidator;
use WPFinet\Tests\TestCase;

/** Frozen import/export semantics for schema 1.0 and 2.0. */
final class ImportExportContractTest extends TestCase {
	private function manager(): ImportManager { return new ImportManager( new FlowValidator() ); }

	private function doc( string $version, string $external ): array {
		return array( 'schema' => 'finet-troubleshoot', 'schema_version' => $version, 'guides' => array( array(
			'external_id' => $external, 'title' => 'Guide ' . $external, 'slug' => $external, 'status' => 'publish', 'start_node' => 'start',
			'nodes' => array(
				array( 'id' => 'start', 'type' => 'question', 'title' => 'Start', 'heading' => 'Fungerar det?', 'options' => array( array( 'id' => 'ja', 'label' => 'Ja', 'next' => 'klart' ), array( 'id' => 'nej', 'label' => 'Nej', 'next' => 'support' ) ) ),
				array( 'id' => 'klart', 'type' => 'result', 'result_type' => 'success', 'title' => 'Klart' ),
				array( 'id' => 'support', 'type' => 'support', 'title' => 'Support', 'support' => array( 'inherit_global' => true ) ),
			),
		) ) );
	}

	private function ids( $result ): array { return array_values( array_filter( wp_list_pluck( $result->to_array()['items'], 'post_id' ) ) ); }

	public function test_schema_1_and_2_import(): void {
		foreach ( array( '1.0', '2.0' ) as $v ) {
			$ext    = 'v' . str_replace( '.', '', $v ) . '-' . strtolower( wp_generate_password( 5, false, false ) );
			$result = $this->manager()->import_data( $this->doc( $v, $ext ), 'copy' );
			$this->assertSame( 1, $result->imported_count(), $v );
			$id = $this->ids( $result )[0];
			$this->created_posts[] = $id;
			$this->assertSame( $ext, get_post_meta( $id, '_finet_ts_external_id', true ) );
			$this->assertSame( 'start', get_post_meta( $id, '_finet_ts_start_node', true ) );
			$this->assertSame( $v, get_post_meta( $id, '_finet_ts_schema_version', true ) );
			$this->assertSame( 'draft', get_post_status( $id ), 'Imports of new guides become drafts' );
		}
	}

	public function test_conflict_strategies_keep_ids(): void {
		$ext = 'strat-' . strtolower( wp_generate_password( 5, false, false ) );
		$first = $this->ids( $this->manager()->import_data( $this->doc( '2.0', $ext ), 'copy' ) )[0];
		$this->created_posts[] = $first;
		$doc = $this->doc( '2.0', $ext );
		$doc['guides'][0]['title'] = 'Uppdaterad titel';
		$update = $this->manager()->import_data( $doc, 'update' );
		$this->assertSame( array( $first ), $this->ids( $update ), 'update keeps post ID' );
		$this->assertSame( 'Uppdaterad titel', get_the_title( $first ) );
		$skip = $this->manager()->import_data( $doc, 'skip' )->to_array();
		$this->assertSame( 1, $skip['skipped'] );
		$this->assertSame( 0, $skip['imported'] );
		$copy = $this->ids( $this->manager()->import_data( $doc, 'copy' ) );
		$this->created_posts[] = $copy[0];
		$this->assertNotSame( $first, $copy[0] );
		$this->assertSame( $ext . '-2', get_post_meta( $copy[0], '_finet_ts_external_id', true ) );
	}

	public function test_roundtrip_export_import_preserves_graph(): void {
		$id     = $this->import_example();
		$export = ( new ExportManager() )->document( array( ( new ExportManager() )->export_guide( $id ) ) );
		$before = get_post_meta( $id, '_finet_ts_nodes', true );
		$result = $this->manager()->import_data( $export, 'update' );
		$this->assertSame( array( $id ), $this->ids( $result ) );
		$this->assertSame( $before, get_post_meta( $id, '_finet_ts_nodes', true ) );
	}

	public function test_validation_errors_and_limit_filters(): void {
		$bad = $this->manager()->preview( array( 'schema' => 'annat', 'schema_version' => '9.9', 'guides' => array() ) );
		$this->assertFalse( $bad['valid'] );
		$this->assertCount( 2, $bad['errors'] );
		$limit = static fn () => 2;
		add_filter( 'finet_troubleshoot_max_nodes_per_guide', $limit );
		$preview = $this->manager()->preview( $this->doc( '2.0', 'limit-test' ) );
		remove_filter( 'finet_troubleshoot_max_nodes_per_guide', $limit );
		$this->assertFalse( $preview['valid'] );
		$broken = $this->doc( '2.0', 'broken-link' );
		$broken['guides'][0]['nodes'][0]['options'][0]['next'] = 'saknas';
		$this->assertFalse( $this->manager()->preview( $broken )['valid'] );
	}

	public function test_import_action_fires(): void {
		$seen = array();
		$cb   = static function ( $post_id, $guide, $strategy ) use ( &$seen ) { $seen[] = array( $post_id, $guide['external_id'], $strategy ); };
		add_action( 'finet_troubleshoot_import_guide', $cb, 10, 3 );
		$ext = 'hook-' . strtolower( wp_generate_password( 5, false, false ) );
		$id  = $this->ids( $this->manager()->import_data( $this->doc( '2.0', $ext ), 'copy' ) )[0];
		remove_action( 'finet_troubleshoot_import_guide', $cb, 10 );
		$this->created_posts[] = $id;
		$this->assertSame( array( array( $id, $ext, 'copy' ) ), $seen );
	}

	public function test_outage_import_is_idempotent_by_external_id(): void {
		$doc = $this->doc( '2.0', 'with-outage-' . strtolower( wp_generate_password( 4, false, false ) ) );
		$doc['outages'] = array( array( 'external_id' => 'ext-outage-1', 'title' => 'Importerad störning', 'slug' => 'importerad-storning', 'status' => 'publish', 'incident_type' => 'planned', 'outage_status' => 'scheduled', 'priority' => 'high', 'incident_start' => '2030-01-01 08:00:00', 'services' => array( 'Internet' ), 'timeline' => array() ) );
		$first  = $this->manager()->import_data( $doc, 'copy' )->to_array();
		$second = $this->manager()->import_data( $doc, 'copy' )->to_array();
		$o1 = array_values( array_filter( $first['items'], static fn ( $i ) => 'Importerad störning' === $i['title'] ) )[0]['post_id'];
		$o2 = array_values( array_filter( $second['items'], static fn ( $i ) => 'Importerad störning' === $i['title'] ) )[0]['post_id'];
		foreach ( array_merge( $first['items'], $second['items'] ) as $i ) { if ( ! empty( $i['post_id'] ) ) { $this->created_posts[] = $i['post_id']; } }
		$this->assertSame( $o1, $o2 );
		$this->assertSame( 'high', get_post_meta( $o1, '_wpfinet_priority', true ) );
		$this->assertSame( array( 'Internet' ), wp_get_post_terms( $o1, 'wpfinet_service', array( 'fields' => 'names' ) ) );
	}
}
