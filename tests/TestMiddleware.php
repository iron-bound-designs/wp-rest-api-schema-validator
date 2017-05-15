<?php
/**
 * Test the middleware class.
 *
 * @author      Iron Bound Designs
 * @since       1.0
 * @copyright   2017 (c) Iron Bound Designs.
 * @license     GPLv2
 */

namespace IronBound\WP_REST_API\SchemaValidator\Tests;

use IronBound\WP_REST_API\SchemaValidator\Middleware;

/**
 * Class TestMiddleware
 *
 * @package IronBound\WP_REST_API\SchemaValidator\Tests
 */
class TestMiddleware extends TestCase {

	/** @var Middleware */
	protected static $middleware;

	public static function setUpBeforeClass() {
		static::$middleware = new Middleware( 'test' );

		return parent::setUpBeforeClass();
	}

	public function setUp() {
		parent::setUp();

		static::$middleware->add_shared_schema( $this->get_shared_schema() );
	}

	public function tearDown() {
		parent::tearDown();

		static::$middleware->deinitialize();
	}

	/** @group testing */
	public function test_register_route_with_single_method() {

		register_rest_route( 'test', 'simple', array(
			'methods'  => 'POST',
			'callback' => function () { return new \WP_REST_Response( array( 'enum' => 'a' ) ); },
			'args'     => $this->get_endpoint_args_for_item_schema( $this->get_schema(), 'POST' ),
			'schema'   => array( $this, 'get_schema' ),
		) );

		static::$middleware->initialize();
		static::$middleware->load_schemas( rest_get_server() );

		$request = \WP_REST_Request::from_url( rest_url( '/test/simple' ) );
		$request->set_method( 'POST' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'enum' => 'd' ) ) );

		$response = $this->server->dispatch( $request );

		$this->assertErrorResponse( 'rest_invalid_param', $response );
	}

	public function test_register_route_with_multiple_methods() {

		register_rest_route( 'test', 'simple', array(
			array(
				'methods'  => 'GET',
				'callback' => function () { return new \WP_REST_Response( array( 'enum' => 'a' ) ); },
				'args'     => $this->get_endpoint_args_for_item_schema( $this->get_schema(), 'GET' ),
			),
			array(
				'methods'  => 'POST',
				'callback' => function () { return new \WP_REST_Response( array( 'enum' => 'a' ) ); },
				'args'     => $this->get_endpoint_args_for_item_schema( $this->get_schema(), 'POST' ),
			),
			'schema' => array( $this, 'get_schema' ),
		) );

		static::$middleware->initialize();
		static::$middleware->load_schemas( rest_get_server() );

		$request = \WP_REST_Request::from_url( rest_url( '/test/simple' ) );
		$request->set_method( 'POST' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'enum' => 'd' ) ) );

		$response = $this->server->dispatch( $request );

		$this->assertErrorResponse( 'rest_invalid_param', $response );
	}

	public function test_shared_schema() {
		register_rest_route( 'test', 'simple', array(
			'methods'  => 'POST',
			'callback' => function () { return new \WP_REST_Response( array( 'enum' => 'a' ) ); },
			'args'     => $this->get_endpoint_args_for_item_schema( $this->get_schema(), 'POST' ),
			'schema'   => array( $this, 'get_schema' ),
		) );

		static::$middleware->initialize();
		static::$middleware->load_schemas( rest_get_server() );

		$request = \WP_REST_Request::from_url( rest_url( '/test/simple' ) );
		$request->set_method( 'POST' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'shared' => array( 'enum' => 5 ) ) ) );

		$response = $this->server->dispatch( $request );

		$this->assertErrorResponse( 'rest_invalid_param', $response );
	}

	public function test_coercion() {

		register_rest_route( 'test', 'simple', array(
			'methods'  => 'POST',
			'callback' => function () { return new \WP_REST_Response( array( 'enum' => 'a' ) ); },
			'args'     => $this->get_endpoint_args_for_item_schema( $this->get_schema(), 'POST' ),
			'schema'   => array( $this, 'get_schema' ),
		) );

		static::$middleware->initialize();
		static::$middleware->load_schemas( rest_get_server() );

		$request = \WP_REST_Request::from_url( rest_url( '/test/simple' ) );
		$request->set_method( 'POST' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array(
			'int' => '2'
		) ) );

		$response = $this->server->dispatch( $request );

		$this->assertInternalType( 'integer', $request['int'] );
		$this->assertEquals( 2, $request['int'] );
	}

	public function test_variable_schema() {

		register_rest_route( 'test', 'simple', array(
			array(
				'methods'  => 'GET',
				'callback' => function () { return new \WP_REST_Response( array( 'enum' => 'a' ) ); },
				'args'     => $this->get_endpoint_args_for_item_schema( $this->get_schema(), 'GET' ),
			),
			array(
				'methods'  => 'POST',
				'callback' => function () { return new \WP_REST_Response( array( 'enum' => 'a' ) ); },
				'args'     => $this->get_endpoint_args_for_item_schema( $this->get_post_schema(), 'POST' ),
				'schema'   => array( $this, 'get_post_schema' ),
			),
			array(
				'methods'  => 'PUT',
				'callback' => function () { return new \WP_REST_Response( array( 'enum' => 'a' ) ); },
				'args'     => $this->get_endpoint_args_for_item_schema( $this->get_schema(), 'PUT' ),
			),
			'schema' => array( $this, 'get_schema' ),
		) );

		static::$middleware->initialize();
		static::$middleware->load_schemas( rest_get_server() );

		$request = \WP_REST_Request::from_url( rest_url( '/test/simple' ) );
		$request->set_method( 'PUT' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'enum' => 'c' ) ) );

		$response = $this->server->dispatch( $request );
		$this->assertArrayHasKey( 'enum', $response->get_data() );

		$request = \WP_REST_Request::from_url( rest_url( '/test/simple' ) );
		$request->set_method( 'POST' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'enum' => 'c' ) ) );

		$response = $this->server->dispatch( $request );

		$this->assertErrorResponse( 'rest_invalid_param', $response );
	}

	public function test_get_request() {

		register_rest_route( 'test', 'simple', array(
			'methods'  => 'GET',
			'callback' => function () { return new \WP_REST_Response( array( 'enum' => 'a' ) ); },
			'args'     => array(
				'getParam' => array(
					'type' => 'string',
					'enum' => array( 'alice', 'bob', 'mallory' )
				),
			),
			'schema'   => array( $this, 'get_schema' ),
		) );

		static::$middleware->initialize();
		static::$middleware->load_schemas( rest_get_server() );

		$request = \WP_REST_Request::from_url( rest_url( '/test/simple' ) );
		$request->set_query_params( array( 'getParam' => 'eve' ) );

		$response = $this->server->dispatch( $request );

		$this->assertErrorResponse( 'rest_invalid_param', $response );
	}

	public function test_get_request_without_schema_registered() {

		register_rest_route( 'test', 'simple', array(
			'methods'  => 'GET',
			'callback' => function () { return new \WP_REST_Response( array( 'enum' => 'a' ) ); },
			'args'     => array(
				'getParam' => array(
					'type' => 'string',
					'enum' => array( 'alice', 'bob', 'mallory' )
				),
			),
		) );

		static::$middleware->initialize();
		static::$middleware->load_schemas( rest_get_server() );

		$request = \WP_REST_Request::from_url( rest_url( '/test/simple' ) );
		$request->set_query_params( array( 'getParam' => 'eve' ) );

		$response = $this->server->dispatch( $request );

		$this->assertErrorResponse( 'rest_invalid_param', $response );
	}

	public function test_delete_request() {

		register_rest_route( 'test', 'simple', array(
			'methods'  => 'DELETE',
			'callback' => function () { return new \WP_REST_Response( null, 204 ); },
			'args'     => array(
				'getParam' => array(
					'type' => 'string',
					'enum' => array( 'alice', 'bob', 'mallory' )
				),
			),
			'schema'   => array( $this, 'get_schema' ),
		) );

		static::$middleware->initialize();
		static::$middleware->load_schemas( rest_get_server() );

		$request = \WP_REST_Request::from_url( rest_url( '/test/simple' ) );
		$request->set_method( 'DELETE' );
		$request->set_query_params( array( 'getParam' => 'eve' ) );

		$response = $this->server->dispatch( $request );

		$this->assertErrorResponse( 'rest_invalid_param', $response );
	}

	public function test_validate_callback_is_called() {

		register_rest_route( 'test', 'simple', array(
			'methods'  => 'POST',
			'callback' => function () { return new \WP_REST_Response( array( 'enum' => 'a' ) ); },
			'args'     => $this->get_endpoint_args_for_item_schema( $this->get_schema(), 'POST' ),
			'schema'   => array( $this, 'get_schema' ),
		) );

		static::$middleware->initialize();
		static::$middleware->load_schemas( rest_get_server() );

		$request = \WP_REST_Request::from_url( rest_url( '/test/simple' ) );
		$request->set_method( 'POST' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'validateCallback' => 'valid' ) ) );

		$response = $this->server->dispatch( $request );
		$this->assertArrayHasKey( 'enum', $response->get_data() );

		$request = \WP_REST_Request::from_url( rest_url( '/test/simple' ) );
		$request->set_method( 'POST' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'validateCallback' => 'invalid' ) ) );

		$response = $this->server->dispatch( $request );

		$this->assertErrorResponse( 'rest_invalid_param', $response );
	}

	public function test_required() {

		register_rest_route( 'test', 'simple', array(
			'methods'  => 'POST',
			'callback' => function () { return new \WP_REST_Response( array( 'enum' => 'a' ) ); },
			'args'     => $this->get_endpoint_args_for_item_schema( $this->get_schema_with_required(), 'POST' ),
			'schema'   => array( $this, 'get_schema_with_required' ),
		) );

		static::$middleware->initialize();
		static::$middleware->load_schemas( rest_get_server() );

		$request = \WP_REST_Request::from_url( rest_url( '/test/simple' ) );
		$request->set_method( 'POST' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'unneeded' => 'hi' ) ) );

		$response = $this->server->dispatch( $request );

		$this->assertErrorResponse( 'rest_missing_callback_param', $response );
	}

	public function test_core_validators_are_removed_by_default() {

		register_rest_route( 'test', 'simple', array(
			'methods'  => 'POST',
			'callback' => function () { return new \WP_REST_Response( array( 'enum' => 'a' ) ); },
			'args'     => $this->get_endpoint_args_for_item_schema( $this->get_schema(), 'POST' ),
			'schema'   => array( $this, 'get_schema' ),
		) );

		static::$middleware->initialize();
		static::$middleware->load_schemas( rest_get_server() );

		$request = \WP_REST_Request::from_url( rest_url( '/test/simple' ) );
		$request->set_method( 'POST' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'enum' => 'b' ) ) );

		$this->server->dispatch( $request );

		$attributes = $request->get_attributes();

		$this->assertArrayHasKey( 'args', $attributes );

		foreach ( $attributes['args'] as $key => $arg ) {

			if ( $key === 'validateCallback' ) {
				continue;
			}

			$this->assertArrayHasKey( 'validate_callback', $arg, "Validate callback exists for {$key}." );
			$this->assertFalse( $arg['validate_callback'] );

			$this->assertArrayHasKey( 'sanitize_callback', $arg, "Sanitize callback exists for {$key}." );
			$this->assertFalse( $arg['sanitize_callback'] );
		}
	}

	public function test_get_schema_route() {

		register_rest_route( 'test', 'simple', array(
			'methods'  => 'POST',
			'callback' => function () { return new \WP_REST_Response( array( 'enum' => 'a' ) ); },
			'args'     => $this->get_endpoint_args_for_item_schema( $this->get_schema(), 'POST' ),
			'schema'   => array( $this, 'get_schema' ),
		) );

		static::$middleware->initialize();
		static::$middleware->load_schemas( rest_get_server() );

		$request = \WP_REST_Request::from_url( static::$middleware->get_url_for_schema( 'test' ) );

		$response = $this->server->dispatch( $request );
		$schema   = $response->get_data();

		$this->assertInternalType( 'array', $schema );
		$this->assertNotEmpty( $schema );
		$this->assertArrayHasKey( 'title', $schema );
		$this->assertEquals( 'test', $schema['title'] );
		$this->assertArrayHasKey( 'properties', $schema );
		$this->assertArrayHasKey( 'enum', $schema['properties'] );
		$this->assertArrayHasKey( 'validateCallback', $schema['properties'] );
		$this->assertArrayNotHasKey( 'arg_options', $schema['properties']['validateCallback'] );
	}

	public function test_default_is_applied() {

		register_rest_route( 'test', 'simple', array(
			'methods'  => 'POST',
			'callback' => function () { return new \WP_REST_Response( array( 'enum' => 'a' ) ); },
			'args'     => $this->get_endpoint_args_for_item_schema( $this->get_schema(), 'POST' ),
			'schema'   => array( $this, 'get_schema' ),
		) );

		static::$middleware->initialize();
		static::$middleware->load_schemas( rest_get_server() );

		$request = \WP_REST_Request::from_url( rest_url( '/test/simple' ) );
		$request->set_method( 'POST' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'enum' => 'a' ) ) );

		$this->server->dispatch( $request );
		$json = $request->get_json_params();
		$this->assertArrayHasKey( 'withDefault', $json );
		$this->assertEquals( 'hi', $json['withDefault'] );
	}

	public function test_invalid_readonly_properties_do_not_error() {

		register_rest_route( 'test', 'simple', array(
			'methods'  => 'POST',
			'callback' => function () { return new \WP_REST_Response( array( 'enum' => 'a' ) ); },
			'args'     => $this->get_endpoint_args_for_item_schema( $this->get_schema(), 'POST' ),
			'schema'   => array( $this, 'get_schema' ),
		) );

		static::$middleware->initialize();
		static::$middleware->load_schemas( rest_get_server() );

		$request = \WP_REST_Request::from_url( rest_url( '/test/simple' ) );
		$request->set_method( 'POST' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'readOnly' => 'd' ) ) );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertNull( $request['readOnly'], 'Read only property set to null.' );
	}

	public function test_invalid_createonly_properties_do_not_error_on_non_create_requests() {

		register_rest_route( 'test', 'simple', array(
			array(
				'methods'  => 'POST',
				'callback' => function () { return new \WP_REST_Response( array( 'enum' => 'a' ) ); },
				'args'     => $this->get_endpoint_args_for_item_schema( $this->get_schema(), 'POST' ),
			),
			array(
				'methods'  => 'PUT',
				'callback' => function () { return new \WP_REST_Response( array( 'enum' => 'a' ) ); },
				'args'     => $this->get_endpoint_args_for_item_schema( $this->get_schema(), 'PUT' ),
			),
			'schema' => array( $this, 'get_schema' ),
		) );

		static::$middleware->initialize();
		static::$middleware->load_schemas( rest_get_server() );

		$request = \WP_REST_Request::from_url( rest_url( '/test/simple' ) );
		$request->set_method( 'PUT' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'createOnly' => 'd' ) ) );

		$response = $this->server->dispatch( $request );
		$this->assertEquals( 200, $response->get_status() );
		$this->assertNull( $request['createOnly'], 'Create only property set to null.' );
	}

	public function test_invalid_createonly_properties_error_on_create_requests() {

		register_rest_route( 'test', 'simple', array(
			'methods'  => 'POST',
			'callback' => function () { return new \WP_REST_Response( array( 'enum' => 'a' ) ); },
			'args'     => $this->get_endpoint_args_for_item_schema( $this->get_schema(), 'POST' ),
			'schema'   => array( $this, 'get_schema' ),
		) );

		static::$middleware->initialize();
		static::$middleware->load_schemas( rest_get_server() );

		$request = \WP_REST_Request::from_url( rest_url( '/test/simple' ) );
		$request->set_method( 'POST' );
		$request->add_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'createOnly' => 'd' ) ) );

		$response = $this->server->dispatch( $request );
		$this->assertErrorResponse( 'rest_invalid_param', $response );
	}

	public function get_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/schema#',
			'title'      => 'test',
			'type'       => 'object',
			'properties' => array(
				'enum'             => array(
					'type' => 'string',
					'enum' => array( 'a', 'b', 'c' )
				),
				'int'              => array(
					'type' => 'integer'
				),
				'shared'           => array(
					'$ref' => static::$middleware->get_url_for_schema( 'shared' ),
				),
				'withDefault'      => array(
					'type'    => 'string',
					'default' => 'hi'
				),
				'readOnly'         => array(
					'type'     => 'string',
					'enum'     => array( 'a', 'b', 'c' ),
					'readonly' => true,
				),
				'createOnly'       => array(
					'type'       => 'string',
					'enum'       => array( 'a', 'b', 'c' ),
					'createonly' => true,
				),
				'validateCallback' => array(
					'type'        => 'string',
					'arg_options' => array(
						'validate_callback' => function ( $value ) {
							return $value === 'valid';
						}
					),
				)
			),
		);
	}

	public function get_post_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/schema#',
			'title'      => 'test',
			'type'       => 'object',
			'properties' => array(
				'enum' => array(
					'type' => 'string',
					'enum' => array( 'a', 'b' )
				),
			),
		);
	}

	public function get_schema_with_required() {
		return array(
			'$schema'    => 'http://json-schema.org/schema#',
			'title'      => 'test',
			'type'       => 'object',
			'properties' => array(
				'needed'   => array(
					'type'     => 'string',
					'required' => true,
				),
				'unneeded' => array(
					'type'     => 'string',
					'required' => false,
				),
			),
		);
	}

	protected function get_shared_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/schema#',
			'title'      => 'shared',
			'type'       => 'object',
			'properties' => array(
				'enum' => array(
					'type' => 'integer',
					'enum' => array( 1, 2, 3 ),
				)
			)
		);
	}
}