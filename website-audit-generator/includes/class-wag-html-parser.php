<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches a URL and extracts structural data from its HTML using DOMDocument.
 * No headless browser — this sees only server-rendered HTML.
 */
class WAG_Html_Parser {

	public $url;
	public $raw_html   = '';
	public $errors      = array();
	private $dom;
	private $xpath;

	public function __construct( $url ) {
		$this->url = $url;
	}

	/**
	 * Fetch the page and prepare the DOM. Returns true on success.
	 */
	public function fetch() {
		// A generic "bot" user-agent gets blocked or rate-limited by a lot of
		// hosting/security stacks (Cloudflare, WAFs, etc.) even on the first
		// request. Presenting as a normal browser avoids most false 403/429s.
		// Timeout is intentionally short (12s) so a slow/unreachable site
		// fails fast instead of stalling the whole audit.
		$response = wp_remote_get(
			$this->url,
			array(
				'timeout'     => 12,
				'redirection' => 5,
				'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
				'headers'     => array(
					'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Language' => 'en-US,en;q=0.9',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->errors[] = $response->get_error_message();
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 429 === $code ) {
			$this->errors[] = 'The target site is rate-limiting automated requests (HTTP 429). This usually means their host/CDN is blocking the audit request — try again in a few minutes, or the site may need to allowlist audit tools.';
			return false;
		}
		if ( 403 === $code ) {
			$this->errors[] = 'The target site blocked this request (HTTP 403), likely due to bot protection (Cloudflare, WAF, etc.).';
			return false;
		}
		if ( $code < 200 || $code >= 400 ) {
			$this->errors[] = sprintf( 'Target site responded with HTTP %d', $code );
			return false;
		}

		$this->raw_html = wp_remote_retrieve_body( $response );
		if ( empty( $this->raw_html ) ) {
			$this->errors[] = 'Empty response body from target URL.';
			return false;
		}

		libxml_use_internal_errors( true );
		$this->dom = new DOMDocument();
		$this->dom->loadHTML( '<?xml encoding="utf-8" ?>' . $this->raw_html );
		libxml_clear_errors();
		$this->xpath = new DOMXPath( $this->dom );

		return true;
	}

	public function get_title() {
		$nodes = $this->dom->getElementsByTagName( 'title' );
		return $nodes->length ? trim( $nodes->item( 0 )->textContent ) : '';
	}

	public function get_meta_content( $name_or_property ) {
		foreach ( array( 'name', 'property' ) as $attr ) {
			$nodes = $this->xpath->query( "//meta[@{$attr}='{$name_or_property}']" );
			if ( $nodes->length ) {
				return trim( $nodes->item( 0 )->getAttribute( 'content' ) );
			}
		}
		return '';
	}

	public function get_meta_description() {
		return $this->get_meta_content( 'description' );
	}

	public function has_viewport_meta() {
		return '' !== $this->get_meta_content( 'viewport' );
	}

	public function get_canonical() {
		$nodes = $this->xpath->query( "//link[@rel='canonical']" );
		return $nodes->length ? $nodes->item( 0 )->getAttribute( 'href' ) : '';
	}

	/**
	 * Returns headings grouped by tag: ['h1' => [...], 'h2' => [...], ...]
	 */
	public function get_headings() {
		$headings = array();
		foreach ( array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ) as $tag ) {
			$nodes            = $this->dom->getElementsByTagName( $tag );
			$headings[ $tag ] = array();
			foreach ( $nodes as $node ) {
				$headings[ $tag ][] = trim( $node->textContent );
			}
		}
		return $headings;
	}

	public function get_images_alt_stats() {
		$images     = $this->dom->getElementsByTagName( 'img' );
		$total      = $images->length;
		$missing    = 0;
		foreach ( $images as $img ) {
			$alt = trim( $img->getAttribute( 'alt' ) );
			if ( '' === $alt ) {
				$missing++;
			}
		}
		return array(
			'total'          => $total,
			'missing_alt'    => $missing,
			'with_alt_ratio' => $total > 0 ? round( ( $total - $missing ) / $total, 2 ) : 1,
		);
	}

	public function get_links_stats() {
		$host       = wp_parse_url( $this->url, PHP_URL_HOST );
		$anchors    = $this->dom->getElementsByTagName( 'a' );
		$internal   = 0;
		$external   = 0;
		$nofollow   = 0;
		$empty_href = 0;

		foreach ( $anchors as $a ) {
			$href = trim( $a->getAttribute( 'href' ) );
			if ( '' === $href || '#' === $href ) {
				$empty_href++;
				continue;
			}
			$link_host = wp_parse_url( $href, PHP_URL_HOST );
			if ( empty( $link_host ) || $link_host === $host ) {
				$internal++;
			} else {
				$external++;
			}
			$rel = strtolower( $a->getAttribute( 'rel' ) );
			if ( false !== strpos( $rel, 'nofollow' ) ) {
				$nofollow++;
			}
		}

		return compact( 'internal', 'external', 'nofollow', 'empty_href' );
	}

	/**
	 * Extract all JSON-LD schema blocks (used for AEO scoring).
	 */
	public function get_schema_blocks() {
		$nodes  = $this->xpath->query( "//script[@type='application/ld+json']" );
		$blocks = array();
		foreach ( $nodes as $node ) {
			$decoded = json_decode( $node->textContent, true );
			if ( null !== $decoded ) {
				$blocks[] = $decoded;
			}
		}
		return $blocks;
	}

	/**
	 * Returns visible body text with tags stripped, for word count / readability.
	 */
	public function get_visible_text() {
		$body = $this->dom->getElementsByTagName( 'body' );
		if ( ! $body->length ) {
			return '';
		}
		// Remove script/style/nav/footer content before extracting text.
		foreach ( array( 'script', 'style', 'nav', 'footer', 'noscript' ) as $tag ) {
			$nodes = $this->dom->getElementsByTagName( $tag );
			for ( $i = $nodes->length - 1; $i >= 0; $i-- ) {
				$node = $nodes->item( $i );
				if ( $node && $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}
		$text = $body->item( 0 )->textContent;
		$text = preg_replace( '/\s+/', ' ', $text );
		return trim( $text );
	}

	public function count_words( $text ) {
		$text = trim( $text );
		if ( '' === $text ) {
			return 0;
		}
		return str_word_count( $text );
	}

	/**
	 * Very rough FAQ / Q&A pattern detector for AEO signal — looks for
	 * headings phrased as questions, which tend to get pulled into
	 * AI-generated answers and featured snippets.
	 */
	public function count_question_headings() {
		$headings = $this->get_headings();
		$count    = 0;
		foreach ( $headings as $tag => $texts ) {
			foreach ( $texts as $t ) {
				if ( preg_match( '/\?\s*$/', trim( $t ) ) ) {
					$count++;
				}
			}
		}
		return $count;
	}

	public function get_dom() {
		return $this->dom;
	}
}
