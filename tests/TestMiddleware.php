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

	public function test_register_route_with_single_method() {

		register_rest_route( 'test', 'simple', array(
			'methods'  => 'POST',
			'callback' => function () { return new \WP_REST_Response( array( 'enum' => 'a' ) ); },
			'args'     => $this->get_endpoint_args_for_item_schema( $this->get_schema(), 'POST' ),
			'schema'   => array( $this, 'get_schema' ),
		) );

		static::$middleware->load_schemas( rest_get_server() );
		static::$middleware->initialize();

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

		static::$middleware->load_schemas( rest_get_server() );
		static::$middleware->initialize();

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

		static::$middleware->load_schemas( rest_get_server() );
		static::$middleware->initialize();

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

		static::$middleware->load_schemas( rest_get_server() );
		static::$middleware->initialize();

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
				'args'     => $this->get_endpoint_args_for_item_schema( $this->get_schema(), 'POST' ),
			),
			'schema' => array( $this, 'get_schema' ),
		) );

		static::$middleware->load_schemas( rest_get_server() );
		static::$middleware->initialize();

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

	public function get_schema() {
		return array(
			'$schema'    => 'http://json-schema.org/schema#',
			'title'      => 'test',
			'type'       => 'object',
			'properties' => array(
				'enum'   => array(
					'type' => 'string',
					'enum' => array( 'a', 'b', 'c' )
				),
				'int'    => array(
					'type' => 'integer'
				),
				'shared' => array(
					'$ref' => static::$middleware->get_url_for_schema( 'shared' ),
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