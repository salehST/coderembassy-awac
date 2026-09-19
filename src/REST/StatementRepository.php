<?php
/**
 * Accessibility statement questionnaire and publication storage.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\REST;

defined( 'ABSPATH' ) || exit;

/**
 * Stores a single editable free-tier accessibility statement draft.
 */
final class StatementRepository {
	private const OPTION = 'ceaw_accessibility_statement';

	/**
	 * Return the current statement draft or honest defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function get() {
		$stored  = get_option( self::OPTION, array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$fields  = self::fields( $stored );
		$content = isset( $stored['content'] ) ? (string) $stored['content'] : '';

		if ( '' === trim( $content ) ) {
			$content = self::generate_content( $fields );
		}

		return self::payload( $fields, $content, $stored );
	}

	/**
	 * Save questionnaire values and editable statement content.
	 *
	 * @param array<string,mixed> $input   Request values.
	 * @param int                 $user_id Current user ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function save( array $input, $user_id ) {
		$current = self::get();
		$fields  = self::fields( array_merge( $current['fields'], $input ) );
		$content = array_key_exists( 'content', $input ) ? wp_kses_post( (string) $input['content'] ) : (string) $current['content'];

		if ( ! empty( $input['regenerate'] ) || '' === trim( $content ) ) {
			$content = self::generate_content( $fields );
		}

		$record = array(
			'fields'       => $fields,
			'content'      => $content,
			'status'       => 'draft',
			'page_id'      => isset( $current['page_id'] ) ? absint( $current['page_id'] ) : 0,
			'updated_at'   => gmdate( 'c' ),
			'updated_by'   => absint( $user_id ),
			'published_at' => isset( $current['published_at'] ) ? (string) $current['published_at'] : '',
		);

		if ( ! update_option( self::OPTION, $record, false ) ) {
			$existing = get_option( self::OPTION, null );
			if ( $existing !== $record ) {
				return new \WP_Error( 'ceaw_statement_store_failed', __( 'AWAC could not save the statement draft.', 'coderembassy-awac' ), array( 'status' => 500 ) );
			}
		}

		return self::payload( $fields, $content, $record );
	}

	/**
	 * Publish the current statement as a WordPress page.
	 *
	 * @param array<string,mixed> $input   Request values.
	 * @param int                 $user_id Current user ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function publish( array $input, $user_id ) {
		$draft = self::save( $input, $user_id );
		if ( is_wp_error( $draft ) ) {
			return $draft;
		}

		$page_id = absint( $draft['page_id'] );
		$post    = $page_id ? get_post( $page_id ) : null;
		/* translators: %s is the organization name shown in the statement page title. */
		$title   = sprintf( __( 'Accessibility statement — %s', 'coderembassy-awac' ), $draft['fields']['organization_name'] );
		$postarr = array(
			'post_title'   => sanitize_text_field( $title ),
			'post_content' => wp_kses_post( $draft['content'] ),
			'post_status'  => 'publish',
			'post_type'    => 'page',
		);

		if ( $post && 'page' === $post->post_type ) {
			$postarr['ID'] = $post->ID;
			$page_id       = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$page_id = wp_insert_post( wp_slash( $postarr ), true );
		}

		if ( is_wp_error( $page_id ) || ! $page_id ) {
			return new \WP_Error( 'ceaw_statement_publish_failed', __( 'AWAC could not publish the statement page.', 'coderembassy-awac' ), array( 'status' => 500 ) );
		}

		$record = array(
			'fields'       => $draft['fields'],
			'content'      => $draft['content'],
			'status'       => 'published',
			'page_id'      => absint( $page_id ),
			'updated_at'   => gmdate( 'c' ),
			'updated_by'   => absint( $user_id ),
			'published_at' => gmdate( 'c' ),
		);
		update_option( self::OPTION, $record, false );

		return self::payload( $record['fields'], $record['content'], $record );
	}

	/**
	 * Build a safe editable statement from questionnaire answers.
	 *
	 * @param array<string,string> $fields Questionnaire fields.
	 * @return string
	 */
	public static function generate_content( array $fields ) {
		$organization = esc_html( $fields['organization_name'] );
		$website      = esc_url( $fields['website_url'] );
		$email        = sanitize_email( $fields['contact_email'] );
		$reviewed     = esc_html( $fields['last_reviewed'] );
		$limitations  = wpautop( esc_html( $fields['known_limitations'] ) );
		$feedback     = wpautop( esc_html( $fields['feedback_instructions'] ) );
		$contact      = '' !== $email ? sprintf( '<a href="mailto:%1$s">%1$s</a>', esc_attr( $email ) ) : __( 'the site contact listed above', 'coderembassy-awac' );

		return sprintf(
			'<h2>%1$s</h2><p>%2$s is working to make this website and its WooCommerce shopping journey more accessible.</p><p>We aim to support WCAG 2.2 AA. This statement describes our current accessibility work and known limitations; it is not a claim that the site conforms to a particular standard.</p>%3$s<h3>Feedback</h3>%4$s<p>To report an accessibility barrier, contact %5$s.</p><p><strong>Last reviewed:</strong> %6$s</p><p><a href="%7$s">Visit %2$s</a></p>',
			$organization,
			$organization,
			'<h3>Known limitations</h3>' . $limitations,
			$feedback,
			$contact,
			$reviewed,
			$website
		);
	}

	/**
	 * Normalize questionnaire fields.
	 *
	 * @param array<string,mixed> $source Input values.
	 * @return array<string,string>
	 */
	private static function fields( array $source ) {
		return array(
			'organization_name'     => substr( sanitize_text_field( $source['organization_name'] ?? get_bloginfo( 'name' ) ), 0, 191 ),
			'website_url'           => esc_url_raw( $source['website_url'] ?? home_url( '/' ) ),
			'contact_email'         => sanitize_email( $source['contact_email'] ?? get_option( 'admin_email' ) ),
			'last_reviewed'         => substr( sanitize_text_field( $source['last_reviewed'] ?? gmdate( 'Y-m-d' ) ), 0, 10 ),
			'known_limitations'     => substr( sanitize_textarea_field( $source['known_limitations'] ?? __( 'Automated checks cover a bounded initial page set. Manual review and assistive-technology testing are still needed.', 'coderembassy-awac' ) ), 0, 2000 ),
			'feedback_instructions' => substr( sanitize_textarea_field( $source['feedback_instructions'] ?? __( 'Tell us which page you were using, what went wrong, and which assistive technology or browser you used.', 'coderembassy-awac' ) ), 0, 2000 ),
		);
	}

	/**
	 * Shape the REST response.
	 *
	 * @param array<string,string> $fields Questionnaire fields.
	 * @param string               $content Statement HTML.
	 * @param array<string,mixed>  $meta Stored metadata.
	 * @return array<string,mixed>
	 */
	private static function payload( array $fields, $content, array $meta ) {
		$page_id = isset( $meta['page_id'] ) ? absint( $meta['page_id'] ) : 0;
		return array(
			'fields'       => $fields,
			'content'      => (string) $content,
			'status'       => isset( $meta['status'] ) && 'published' === $meta['status'] ? 'published' : 'draft',
			'page_id'      => $page_id,
			'page_url'     => $page_id ? (string) get_permalink( $page_id ) : '',
			'updated_at'   => isset( $meta['updated_at'] ) ? (string) $meta['updated_at'] : '',
			'published_at' => isset( $meta['published_at'] ) ? (string) $meta['published_at'] : '',
		);
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
