<?php
/**
 * Shared query service for all outage surfaces.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Outages;

final class OutageRepository {
	/** @return array<int,\WP_Post> */
	public function find( string $bucket = 'current', array $filters = array(), int $limit = 20 ): array {
		$key = 'wpfinet_outages_' . md5( wp_json_encode( array( $bucket, $filters, $limit ) ) ); $cached = get_transient( $key );
		if ( is_array( $cached ) ) { return $cached; }
		$args = array( 'post_type' => OutageModule::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => min( 100, max( 1, $limit ) ), 'orderby' => array( 'meta_value_num' => 'ASC', 'date' => 'DESC' ), 'meta_key' => OutageModule::META_START, 'no_found_rows' => true );
		$tax_query = array();
		foreach ( array( 'provider' => OutageModule::TAX_PROVIDER, 'service' => OutageModule::TAX_SERVICE, 'area' => OutageModule::TAX_AREA ) as $key_name => $taxonomy ) {
			if ( ! empty( $filters[ $key_name ] ) ) { $tax_query[] = array( 'taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => sanitize_title( (string) $filters[ $key_name ] ) ); }
		}
		if ( $tax_query ) { $args['tax_query'] = $tax_query; }
		$posts = get_posts( $args ); $now = current_time( 'timestamp' );
		$posts = array_values( array_filter( $posts, fn ( \WP_Post $post ): bool => $bucket === $this->classify( $post, $now ) ) );
		usort( $posts, fn ( \WP_Post $left, \WP_Post $right ): int => $this->priority( $right ) <=> $this->priority( $left ) ?: strcmp( $this->start( $left ), $this->start( $right ) ) );
		set_transient( $key, $posts, 5 * MINUTE_IN_SECONDS );
		return $posts;
	}
	public function classify( \WP_Post $post, ?int $now = null ): string {
		$now ??= current_time( 'timestamp' ); $status = (string) get_post_meta( $post->ID, OutageModule::META_STATUS, true );
		if ( in_array( $status, array( 'resolved', 'cancelled' ), true ) || get_post_meta( $post->ID, OutageModule::META_ACTUAL_END, true ) ) { return 'past'; }
		$start = $this->start_timestamp( $post );
		return $start > $now ? 'upcoming' : 'current';
	}
	public function start( \WP_Post $post ): string { return (string) get_post_meta( $post->ID, OutageModule::META_START, true ); }
	public function start_timestamp( \WP_Post $post ): int {
		$value = $this->start( $post ) ?: $post->post_date;
		$time  = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, wp_timezone() );
		return $time ? $time->getTimestamp() : 0;
	}
	public function priority( \WP_Post $post ): int { return array_search( get_post_meta( $post->ID, OutageModule::META_PRIORITY, true ), array( 'low', 'normal', 'high', 'critical' ), true ) ?: 0; }
	/** @return array<string,mixed> */
	public function public_data( \WP_Post $post, bool $detail = true ): array {
		$timeline = get_post_meta( $post->ID, OutageModule::META_TIMELINE, true ); $timeline = is_array( $timeline ) ? array_values( array_filter( $timeline, static fn ( array $item ): bool => ! empty( $item['public'] ) ) ) : array();
		return array(
			'id' => $post->ID, 'title' => $post->post_title, 'url' => get_permalink( $post ), 'type' => (string) get_post_meta( $post->ID, OutageModule::META_TYPE, true ), 'status' => (string) get_post_meta( $post->ID, OutageModule::META_STATUS, true ), 'classification' => $this->classify( $post ), 'priority' => (string) get_post_meta( $post->ID, OutageModule::META_PRIORITY, true ), 'start' => $this->start( $post ), 'estimated_end' => (string) get_post_meta( $post->ID, OutageModule::META_ESTIMATED_END, true ), 'actual_end' => (string) get_post_meta( $post->ID, OutageModule::META_ACTUAL_END, true ), 'providers' => wp_get_post_terms( $post->ID, OutageModule::TAX_PROVIDER, array( 'fields' => 'names' ) ), 'services' => wp_get_post_terms( $post->ID, OutageModule::TAX_SERVICE, array( 'fields' => 'names' ) ), 'areas' => wp_get_post_terms( $post->ID, OutageModule::TAX_AREA, array( 'fields' => 'names' ) ), 'area_note' => (string) get_post_meta( $post->ID, OutageModule::META_AREA_NOTE, true ), 'summary' => $post->post_excerpt, 'content' => $detail ? apply_filters( 'the_content', $post->post_content ) : '', 'timeline' => $timeline,
		);
	}
	public function flush(): void { global $wpdb; $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpfinet_outages_%' OR option_name LIKE '_transient_timeout_wpfinet_outages_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
