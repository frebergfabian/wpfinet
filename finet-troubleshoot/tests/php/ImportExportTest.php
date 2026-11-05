<?php
namespace FinetTroubleshoot\Tests;

use FinetTroubleshoot\Export\ExportManager;
use FinetTroubleshoot\Import\ImportManager;
use FinetTroubleshoot\Validation\FlowValidator;

class ImportExportTest extends \WP_UnitTestCase {
	public function test_example_import_export_roundtrip_preserves_graph(): void {
		$source = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/examples/finet-troubleshoot-example.json' ), true );
		$manager = new ImportManager( new FlowValidator() ); $result = $manager->import_data( $source, 'copy' ); $this->assertSame( 1, $result->imported_count() );
		$exported = ( new ExportManager() )->export_all(); $this->assertSame( 'finet-troubleshoot', $exported['schema'] ); $this->assertSame( $source['guides'][0]['start_node'], $exported['guides'][0]['start_node'] ); $this->assertCount( count( $source['guides'][0]['nodes'] ), $exported['guides'][0]['nodes'] );
	}
}
