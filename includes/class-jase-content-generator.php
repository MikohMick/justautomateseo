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
        // Convert HTML to Gutenberg blocks for block editor compatibility
        $content = $this->convert_to_blocks( $content );

        $post_data = [
            'post_title'   => sanitize_text_field( $title ),
            'post_content' => $content,
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

    /**
     * Convert HTML content to Gutenberg block format.
     */
    public function convert_to_blocks( $html ) {
        // Normalize line breaks and trim
        $html = trim( $html );
        if ( empty( $html ) ) {
            return '';
        }

        // Split HTML into top-level elements
        $blocks = [];
        $dom    = new DOMDocument();
        // Suppress warnings for HTML5 tags; use UTF-8 encoding
        @$dom->loadHTML( '<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

        $wrapper = $dom->getElementsByTagName( 'div' )->item( 0 );
        if ( ! $wrapper ) {
            // Fallback: wrap entire content in a single HTML block
            return "<!-- wp:html -->\n" . wp_kses_post( $html ) . "\n<!-- /wp:html -->";
        }

        foreach ( $wrapper->childNodes as $node ) {
            if ( $node->nodeType === XML_TEXT_NODE ) {
                $text = trim( $node->textContent );
                if ( ! empty( $text ) ) {
                    $blocks[] = "<!-- wp:paragraph -->\n<p>" . esc_html( $text ) . "</p>\n<!-- /wp:paragraph -->";
                }
                continue;
            }

            if ( $node->nodeType !== XML_ELEMENT_NODE ) {
                continue;
            }

            $tag       = strtolower( $node->nodeName );
            $inner_html = $this->get_inner_html( $dom, $node );
            $outer_html = $dom->saveHTML( $node );

            switch ( $tag ) {
                case 'h2':
                    $blocks[] = "<!-- wp:heading -->\n" . $outer_html . "\n<!-- /wp:heading -->";
                    break;

                case 'h3':
                    $blocks[] = "<!-- wp:heading {\"level\":3} -->\n" . $outer_html . "\n<!-- /wp:heading -->";
                    break;

                case 'h4':
                    $blocks[] = "<!-- wp:heading {\"level\":4} -->\n" . $outer_html . "\n<!-- /wp:heading -->";
                    break;

                case 'p':
                    $blocks[] = "<!-- wp:paragraph -->\n" . $outer_html . "\n<!-- /wp:paragraph -->";
                    break;

                case 'ul':
                    $blocks[] = "<!-- wp:list -->\n" . $outer_html . "\n<!-- /wp:list -->";
                    break;

                case 'ol':
                    $blocks[] = "<!-- wp:list {\"ordered\":true} -->\n" . $outer_html . "\n<!-- /wp:list -->";
                    break;

                case 'blockquote':
                    $blocks[] = "<!-- wp:quote -->\n" . $outer_html . "\n<!-- /wp:quote -->";
                    break;

                case 'table':
                    $blocks[] = "<!-- wp:table -->\n<figure class=\"wp-block-table\">" . $outer_html . "</figure>\n<!-- /wp:table -->";
                    break;

                case 'hr':
                    $blocks[] = "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
                    break;

                default:
                    // Wrap unknown elements in an HTML block
                    $blocks[] = "<!-- wp:html -->\n" . $outer_html . "\n<!-- /wp:html -->";
                    break;
            }
        }

        return implode( "\n\n", $blocks );
    }

    /**
     * Get innerHTML of a DOMNode.
     */
    private function get_inner_html( $dom, $node ) {
        $inner = '';
        foreach ( $node->childNodes as $child ) {
            $inner .= $dom->saveHTML( $child );
        }
        return $inner;
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
