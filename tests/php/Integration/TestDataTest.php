<?php
namespace WPFinet\Tests\Integration;

use FinetTroubleshoot\Access\AccessService;
use FinetTroubleshoot\Access\AccessSettings;
use FinetTroubleshoot\Admin\TestData;
use FinetTroubleshoot\Instagram\InstagramService;
use WPFinet\Tests\TestCase;

final class TestDataTest extends TestCase {
	public function test_create_and_remove_leave_no_trace(): void {
		delete_option( AccessSettings::OPTION );
		$before = wp_count_posts( 'page' );
		$this->assertTrue( TestData::create()['ok'] );
		$this->assertSame( 'private', get_page_by_path( 'wpfinet-test-sparare' )->post_status );
		$this->assertStringContainsString( '<script', get_page_by_path( 'wpfinet-test-sparare' )->post_content );
		$this->assertSame( 'connected', ( new AccessService() )->lookup( 'Exempelgatan 1' )['status'] );
		$this->assertCount( 6, InstagramService::media() );
		$this->assertTrue( TestData::create()['ok'], 'idempotent' );
		$this->assertTrue( TestData::remove()['ok'] );
		$this->assertNull( get_page_by_path( 'wpfinet-test-sparare' ) );
		$this->assertSame( array(), InstagramService::media() );
		$this->assertSame( 'http', AccessSettings::get()['adapter'] );
		$this->assertSame( 'unavailable', ( new AccessService() )->lookup( 'Exempelgatan 1' )['status'], 'no fictitious answers after removal' );
		$this->assertEquals( $before, wp_count_posts( 'page' ) );
	}
}
