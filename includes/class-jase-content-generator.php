<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class JASE_Content_Generator {

    public function fetch_sitemap_urls( $sitemap_url ) {
        $response = wp_remote_get( $sitemap_url, [ 'timeout' => 30 ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            return new WP_Error( 'sitemap_error', 'Sitemap returned status ' . $code );
        }

        $xml_string = wp_remote_retrieve_body( $response );
        libxml_use_internal_errors( true );
        $xml = simplexml_load_string( $xml_string );
        if ( ! $xml ) {
            return new WP_Error( 'xml_parse_error', 'Failed to parse sitemap XML' );
        }

        $urls = [];
        $namespaces = $xml->getNamespaces( true );
        $ns = $namespaces[''] ?? '';

        if ( $ns ) {
            $xml->registerXPathNamespace( 'sm', $ns );
            $entries = $xml->xpath( '//sm:url/sm:loc' );
        } else {
            $entries = $xml->xpath( '//url/loc' );
        }

        if ( $entries ) {
            foreach ( $entries as $entry ) {
                $urls[] = (string) $entry;
            }
        }

        return $urls;
    }

    public function create_post( $title, $content, $status = 'draft', $category_id = 0, $featured_image_url = '' ) {
        $post_data = [
            'post_title'   => sanitize_text_field( $title ),
            'post_content' => wp_kses_post( $content ),
            'post_status'  => $status,
            'post_type'    => 'post',
            'post_author'  => get_current_user_id(),
        ];

        if ( $category_id > 0 ) {
            $post_data['post_category'] = [ $category_id ];
        }

        $post_id = wp_insert_post( $post_data );
        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        if ( ! empty( $featured_image_url ) ) {
            $this->set_featured_image( $post_id, $featured_image_url, $title );
        }

        return $post_id;
    }

    public function set_featured_image( $post_id, $image_url, $title = '' ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $image_url );
        if ( is_wp_error( $tmp ) ) {
            return $tmp;
        }

        $file_array = [
            'name'     => sanitize_file_name( $title ) . '.png',
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file_array, $post_id, $title );
        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            return $attachment_id;
        }

        set_post_thumbnail( $post_id, $attachment_id );

        // Set alt text
        update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $title ) );

        return $attachment_id;
    }
}
