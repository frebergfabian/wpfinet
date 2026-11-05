<?php
namespace FinetTroubleshoot\Tests;

use FinetTroubleshoot\Validation\FlowValidator;

class FlowValidatorTest extends \WP_UnitTestCase {
	private function guide(): array {
		return array(
			'start_node' => 'start',
			'nodes' => array(
				array( 'id' => 'start', 'type' => 'question', 'title' => 'Start', 'options' => array( array( 'id' => 'yes', 'label' => 'Ja', 'next' => 'done' ) ) ),
				array( 'id' => 'done', 'type' => 'result', 'result_type' => 'success', 'title' => 'Klart', 'options' => array() ),
			),
		);
	}
	public function test_valid_branching_flow_is_valid(): void { $result = ( new FlowValidator() )->validate( $this->guide() ); $this->assertTrue( $result['valid'] ); }
	public function test_broken_next_node_is_rejected(): void { $guide = $this->guide(); $guide['nodes'][0]['options'][0]['next'] = 'missing'; $result = ( new FlowValidator() )->validate( $guide ); $this->assertFalse( $result['valid'] ); $this->assertStringContainsString( 'finns inte', implode( ' ', $result['errors'] ) ); }
	public function test_cycle_is_a_warning_not_an_error(): void { $guide = $this->guide(); $guide['nodes'][1]['type'] = 'information'; $guide['nodes'][1]['next'] = 'start'; $guide['nodes'][] = array( 'id' => 'unused-result', 'type' => 'result', 'title' => 'Resultat' ); $result = ( new FlowValidator() )->validate( $guide ); $this->assertTrue( $result['valid'] ); $this->assertNotEmpty( $result['warnings'] ); }
	public function test_duplicate_node_is_rejected(): void { $guide = $this->guide(); $guide['nodes'][] = $guide['nodes'][0]; $result = ( new FlowValidator() )->validate( $guide ); $this->assertFalse( $result['valid'] ); }
}
