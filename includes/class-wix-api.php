<?php
/**
 * Wix API Class
 *
 * Handles communication with the Wix API for content migration
 *
 * @package WixToWordPressMigrator
 * @since 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wix API handler class
 *
 * @since 1.0.0
 */
class Wix_API {

	/**
	 * Wix client ID
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private $client_id;

	/**
	 * Access token for Wix API
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private $access_token;

	/**
	 * Wix API base URL
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private $base_url = 'https://www.wixapis.com';

	/**
	 * Constructor
	 *
	 * @param string|null $client_id Wix client ID.
	 * @since 1.0.0
	 */
	public function __construct( $client_id = null ) {
		if ( $client_id ) {
			$this->client_id = sanitize_text_field( $client_id );
		}
		$this->access_token = get_transient( 'wix_access_token' );
	}
    
	/**
	 * Authenticate with Wix API
	 *
	 * @param string $client_id Wix client ID.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 * @since 1.0.0
	 */
	public function authenticate( $client_id ) {
		// Sanitize input
		$client_id = sanitize_text_field( $client_id );
		if ( empty( $client_id ) ) {
			return new WP_Error( 'invalid_client_id', __( 'Invalid client ID provided.', 'wix-to-wp-migrator' ) );
		}

		$this->client_id = $client_id;

		$response = wp_remote_post(
			$this->base_url . '/oauth2/token',
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'clientId'  => $client_id,
						'grantType' => 'anonymous',
					)
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'auth_failed',
				sprintf(
					/* translators: %s: Error message */
					__( 'Failed to authenticate with Wix API: %s', 'wix-to-wp-migrator' ),
					$response->get_error_message()
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! isset( $data['access_token'] ) ) {
			return new WP_Error( 'auth_failed', __( 'No access token received from Wix API', 'wix-to-wp-migrator' ) );
		}

		$this->access_token = sanitize_text_field( $data['access_token'] );

		// Cache the access token
		$expires_in = isset( $data['expires_in'] ) ? absint( $data['expires_in'] ) : 14400;
		set_transient( 'wix_access_token', $this->access_token, $expires_in );

		return true;
	}
    
    public function get_categories($offset = 0, $limit = 100) {
        if (!$this->access_token) {
            return new WP_Error('no_token', 'No access token available. Please authenticate first.');
        }
        
        $url = $this->base_url . '/blog/v3/categories';
        $url = add_query_arg(array(
            'fieldsets' => 'URL',
            'offset' => $offset,
            'limit' => $limit
        ), $url);
        
        return $this->make_request($url);
    }
    
    public function get_tags($offset = 0, $limit = 100) {
        if (!$this->access_token) {
            return new WP_Error('no_token', 'No access token available. Please authenticate first.');
        }
        
        $response = wp_remote_post($this->base_url . '/blog/v2/tags/query', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->access_token
            ),
            'body' => wp_json_encode(array(
                'paging' => array(
                    'offset' => $offset,
                    'limit' => $limit
                )
            )),
            'timeout' => 30
        ));
        
        return $this->process_response($response);
    }
    
    public function get_posts($offset = 0, $limit = 100) {
        if (!$this->access_token) {
            return new WP_Error('no_token', 'No access token available. Please authenticate first.');
        }
        
        // Use POST method with proper paging structure according to Wix API docs
        $response = wp_remote_post($this->base_url . '/blog/v3/posts/query', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->access_token
            ),
            'body' => wp_json_encode(array(
                'fieldsToInclude' => array('RICH_CONTENT'),
                'paging' => array(
                    'offset' => $offset,
                    'limit' => min($limit, 100) // Max 100 per request
                ),
                'sort' => array(
                    array(
                        'fieldName' => 'firstPublishedDate',
                        'order' => 'DESC'
                    )
                )
            )),
            'timeout' => 60 // Increase timeout for large requests
        ));
        
        return $this->process_response($response);
    }
    
    private function make_request($url) {
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->access_token
            ),
            'timeout' => 30
        ));
        
        return $this->process_response($response);
    }
    
    private function process_response($response) {
        if (is_wp_error($response)) {
            return $response;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            return new WP_Error('api_error', 'Wix API returned error: ' . $response_code . ' - ' . $body);
        }
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_error', 'Invalid JSON response from Wix API');
        }
        
        return $data;
    }
    
    public function is_authenticated() {
        return !empty($this->access_token);
    }
}