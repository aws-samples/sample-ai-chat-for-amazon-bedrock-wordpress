<?php
/**
 * Read-only tools over this site's PUBLIC content.
 *
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: MIT-0
 *
 * @package AICFAB
 */

namespace AICFAB;

defined( 'ABSPATH' ) || exit;

/**
 * The model can search and read published, non-password-protected posts and pages, i.e. only what
 * any anonymous visitor can already read. Content is taken from post_content with shortcodes and
 * tags stripped; no the_content filters run, so no other plugin's code executes on the model's behalf.
 */
final class Site_Tools {

	const MAX_CONTENT = 8000;

	/**
	 * Tool specifications in Bedrock toolSpec form.
	 *
	 * @return array<string,array{description:string,schema:object,handler:callable}>
	 */
	public static function definitions() {
		return array(
			'site_search_posts' => array(
				'description' => 'Search published posts and pages on this website. Returns up to 5 results with id, title, url, date and excerpt.',
				'schema'      => (object) array(
					'type'       => 'object',
					'properties' => (object) array(
						'query' => (object) array(
							'type'        => 'string',
							'description' => 'Search keywords.',
						),
						'limit' => (object) array(
							'type'        => 'integer',
							'description' => 'Number of results, 1-5.',
						),
					),
					'required'   => array( 'query' ),
				),
				'handler'     => array( __CLASS__, 'search_posts' ),
			),
			'site_get_post'     => array(
				'description' => 'Read one published post or page on this website by id (from site_search_posts). Returns title, url, date and text content.',
				'schema'      => (object) array(
					'type'       => 'object',
					'properties' => (object) array(
						'id' => (object) array(
							'type'        => 'integer',
							'description' => 'Post id.',
						),
					),
					'required'   => array( 'id' ),
				),
				'handler'     => array( __CLASS__, 'get_post' ),
			),
			'site_info'         => array(
				'description' => 'Get this website\'s name, tagline and home URL.',
				'schema'      => (object) array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
				'handler'     => array( __CLASS__, 'site_info' ),
			),
		);
	}

	/**
	 * Post types the tools may read.
	 *
	 * @return string[]
	 */
	private static function post_types() {
		$types = apply_filters( 'aicfab_site_tool_post_types', array( 'post', 'page' ) );
		return array_values( array_filter( (array) $types, 'is_post_type_viewable' ) );
	}

	/**
	 * Search.
	 *
	 * @param object $input Tool input.
	 * @return array
	 */
	public static function search_posts( $input ) {
		$query = isset( $input->query ) ? sanitize_text_field( (string) $input->query ) : '';
		$limit = isset( $input->limit ) ? Settings::clamp_int( $input->limit, 1, 5 ) : 5;
		if ( '' === $query ) {
			return array( 'error' => 'query is required' );
		}
		$posts   = get_posts(
			array(
				's'                => substr( $query, 0, 200 ),
				'post_type'        => self::post_types(),
				'post_status'      => 'publish',
				'has_password'     => false,
				'posts_per_page'   => $limit,
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		$results = array();
		foreach ( $posts as $post ) {
			if ( self::is_public( $post ) ) {
				$results[] = array(
					'id'      => (int) $post->ID,
					'title'   => get_the_title( $post ),
					'url'     => get_permalink( $post ),
					'date'    => get_the_date( 'Y-m-d', $post ),
					'excerpt' => wp_trim_words( self::plain_text( $post->post_excerpt ? $post->post_excerpt : $post->post_content ), 40 ),
				);
			}
		}
		return array( 'results' => $results );
	}

	/**
	 * Read one post.
	 *
	 * @param object $input Tool input.
	 * @return array
	 */
	public static function get_post( $input ) {
		$post = isset( $input->id ) ? get_post( absint( $input->id ) ) : null;
		if ( ! $post || ! self::is_public( $post ) ) {
			return array( 'error' => 'not found' );
		}
		return array(
			'id'      => (int) $post->ID,
			'title'   => get_the_title( $post ),
			'url'     => get_permalink( $post ),
			'date'    => get_the_date( 'Y-m-d', $post ),
			'content' => Mcp_Servers::clean_text( self::plain_text( $post->post_content ), self::MAX_CONTENT ),
		);
	}

	/**
	 * Public site metadata.
	 *
	 * @return array
	 */
	public static function site_info() {
		return array(
			'name'    => get_bloginfo( 'name' ),
			'tagline' => get_bloginfo( 'description' ),
			'url'     => home_url( '/' ),
		);
	}

	/**
	 * Whether a post is readable by an anonymous visitor.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	private static function is_public( $post ) {
		return $post instanceof \WP_Post
			&& 'publish' === $post->post_status
			&& '' === $post->post_password
			&& in_array( $post->post_type, self::post_types(), true )
			&& ( ! function_exists( 'is_post_publicly_viewable' ) || is_post_publicly_viewable( $post ) );
	}

	/**
	 * Plain text from post content without running content filters.
	 *
	 * @param string $content Raw content.
	 * @return string
	 */
	private static function plain_text( $content ) {
		$text = wp_strip_all_tags( strip_shortcodes( excerpt_remove_blocks( (string) $content ) ) );
		return trim( preg_replace( '/\s+/', ' ', html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );
	}
}
