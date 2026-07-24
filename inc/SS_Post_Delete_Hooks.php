<?php
/**
 * Post-delete auto-clean hook.
 *
 * Opt-in (Settings → Scheduled Scans → Auto cleanup → "Orphans left behind
 * by a permanently deleted post"). When a post is permanently deleted, its
 * featured image and any images referenced in its own content are checked
 * for use anywhere *else* on the site; anything with no other reference is
 * moved to Safe Trash — restorable, never a hard delete, same guarantee
 * every other cleanup path in this plugin makes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SS_Post_Delete_Hooks {

	public static function init() {
		add_action( 'before_delete_post', array( __CLASS__, 'maybe_clean_orphaned_attachments' ) );
	}

	public static function maybe_clean_orphaned_attachments( $post_id ) {
		$settings = storage_sherpa_get_settings();

		if ( ! in_array( 'post_delete_cleanup', (array) $settings['auto_cleanup'], true ) ) {
			return;
		}

		if ( ! class_exists( 'SS_Orphan_Media_Scanner' ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post || 'attachment' === $post->post_type ) {
			return;
		}

		$batch_id = null;

		foreach ( self::candidate_attachment_ids( $post ) as $attachment_id ) {
			if ( SS_Ignore_Rules::is_attachment_ignored( $attachment_id ) ) {
				continue;
			}

			if ( SS_Orphan_Media_Scanner::is_attachment_referenced_elsewhere( $attachment_id, $post_id ) ) {
				continue;
			}

			if ( null === $batch_id ) {
				$batch_id = wp_generate_password( 20, false, false );
			}

			SS_Trash::trash_attachment( $attachment_id, 'post_delete_cleanup', $batch_id );
		}
	}

	/**
	 * The featured image plus any attachment ids the classic/block editor
	 * already tagged in this post's own saved content (wp-image-N classes)
	 * — deliberately narrow, since this hook only ever needs to reconsider
	 * images this specific post was the one place referencing.
	 */
	private static function candidate_attachment_ids( $post ) {
		$ids = array();

		$thumbnail_id = get_post_thumbnail_id( $post );
		if ( $thumbnail_id ) {
			$ids[] = (int) $thumbnail_id;
		}

		if ( $post->post_content && preg_match_all( '/wp-image-(\d+)/', $post->post_content, $m ) ) {
			$ids = array_merge( $ids, array_map( 'intval', $m[1] ) );
		}

		return array_unique( array_filter( $ids ) );
	}
}
