<?php
/**
 * Schema Validator
 *
 * @author      Iron Bound Designs
 * @since       1.0
 * @copyright   2017 (c) Iron Bound Designs.
 * @license     GPLv2
 */

namespace IronBound\WP_REST_API\SchemaValidator;

use JsonSchema\Constraints\Constraint;
use JsonSchema\Constraints\Factory;
use JsonSchema\Exception\ResourceNotFoundException;
use JsonSchema\SchemaStorage;
use JsonSchema\Uri\Retrievers\PredefinedArray;
use JsonSchema\Uri\UriRetriever;
use JsonSchema\Validator;

/**
 * Class Middleware
 *
 * @package IronBound\WP_REST_API\SchemaValidator
 */
class Middleware {

	/** @var string */
	private $namespace;

	/** @var array */
	private $strings;

	/** @var int */
	private $check_mode;

	/** @var array[] */
	private $shared_schemas = array();

	/** @var UriRetriever */
	private $uri_retriever;

	/** @var SchemaStorage */
	private $schema_storage;

	/** @var array[] '/wp/v2/posts' => [ 'GET' => 'posts', 'POST' => 'posts-post', 'PUT' => 'posts' ] */
	private $routes_to_schema_urls = array();

	/**
	 * Middleware constructor.
	 *
	 * @param string $namespace
	 * @param array  $strings
	 * @param int    $check_mode Check mode. See Constraint class constants.
	 */
	public function __construct( $namespace, array $strings = array(), $check_mode = 0 ) {
		$this->namespace = trim( $namespace, '/' );
		$this->strings   = wp_parse_args( $strings, array(
			'methodParamDescription' => 'HTTP method to get the schema for. If not provided, will use the base schema.',
			'schemaNotFound'         => 'Schema not found.',
		) );

		if ( $check_mode === 0 ) {
			$check_mode = Constraint::CHECK_MODE_NORMAL | Constraint::CHECK_MODE_APPLY_DEFAULTS | Constraint::CHECK_MODE_COERCE_TYPES;
		}

		$this->check_mode = $check_mode;
	}

	/**
	 * Initialize the middleware.
	 *
	 * @since 1.0.0
	 */
	public function initialize() {
		add_filter( 'rest_dispatch_request', array( $this, 'validate_and_conform_request' ), 10, 4 );
		add_action( 'rest_api_init', array( $this, 'load_schemas' ), 100 );
		add_filter( 'rest_endpoints', array( $this, 'remove_default_validators_and_set_variable_schemas' ) );
	}

	/**
	 * Deinitialize the middleware and remove filters.
	 *
	 * @since 1.0.0
	 */
	public function deinitialize() {
		remove_filter( 'rest_dispatch_request', array( $this, 'validate_and_conform_request' ), 10 );
		remove_action( 'rest_api_init', array( $this, 'load_schemas' ), 100 );
		remove_filter( 'rest_endpoints', array( $this, 'remove_default_validators_and_set_variable_schemas' ) );
	}

	/**
	 * Add a schema that is not attached to a particular route, but can still be referenced by URL.
	 *
	 * @since 1.0.0
	 *
	 * @param array $schema
	 */
	public function add_shared_schema( array $schema ) {
		$this->shared_schemas[] = $schema;
	}

	/**
	 * After the routes have been registered with the REST server, load all of their schemas into schema storage.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Server $server
	 */
	public function load_schemas( \WP_REST_Server $server ) {

		$endpoints      = $this->get_endpoints_for_namespace( $server );
		$schemas        = array();
		$urls_by_method = array();

		foreach ( $endpoints as $route => $handlers ) {

			$options = $server->get_route_options( $route );

			if ( empty( $options['schema'] ) ) {
				if ( empty( $handlers[0]['schema'] ) ) {
					continue;
				}

				$default_schema = call_user_func( $handlers[0]['schema'] );
			} else {
				$default_schema = call_user_func( $options['schema'] );
			}

			if ( empty( $default_schema['title'] ) ) {
				continue;
			}

			$default_title       = $default_schema['title'];
			$default_url         = $this->get_url_for_schema( $default_title );
			$default_schema_json = $this->transform_schema_to_json( $default_schema );

			$urls_by_method[ $route ] = array();

			if ( isset( $handlers['callback'] ) ) {
				$handlers = array( $handlers );
			}

			// Allow for different schemas per HTTP Method.
			foreach ( $handlers as $i => $handler ) {

				foreach ( $handler['methods'] as $method => $_ ) {

					if ( ! isset( $options["schema-{$method}"] ) ) {
						$schemas[ $default_url ]             = $default_schema_json;
						$urls_by_method[ $route ][ $method ] = $default_url;

						continue;
					}

					$method_schema_json = $this->transform_schema_to_json( call_user_func( $options["schema-{$method}"] ) );
					$url                = $this->get_url_for_schema( $default_title, $method );

					$urls_by_method[ $route ][ $method ] = $url;

					$schemas[ $url ] = $method_schema_json;
				}
			}
		}

		foreach ( $this->shared_schemas as $shared_schema ) {
			$schemas[ $this->get_url_for_schema( $shared_schema['title'] ) ] = wp_json_encode( $shared_schema );
		}

		$strategy            = new PredefinedArray( $schemas );
		$this->uri_retriever = new UriRetriever();
		$this->uri_retriever->setUriRetriever( $strategy );

		$this->schema_storage        = new SchemaStorage( $this->uri_retriever );
		$this->routes_to_schema_urls = $urls_by_method;

		$this->register_schema_route();
	}

	/**
	 * Validate a request and conform it to the schema.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Response|null|\WP_Error $response
	 * @param \WP_REST_Request                 $request
	 * @param string                           $route
	 * @param array                            $handler
	 *
	 * @return \WP_REST_Response|null|\WP_Error
	 */
	public function validate_and_conform_request( $response, $request, $route, $handler ) {

		if ( $response !== null ) {
			return $response;
		}

		$method = $request->get_method();
		$map    = $this->routes_to_schema_urls;

		if ( $method === 'GET' || $method === 'DELETE' ) {
			$schema_object = json_decode( $this->transform_schema_to_json( array(
				'type'       => 'object',
				'properties' => $handler['args'],
			) ) );
		} elseif ( isset( $map[ $route ], $map[ $route ][ $method ] ) ) {
			$schema_object = clone $this->schema_storage->getSchema( $map[ $route ][ $method ] );
		} else {
			return $response;
		}

		$defaults = $request->get_default_params();
		$request->set_default_params( array() );
		$to_validate = $request->get_params();
		$request->set_default_params( $defaults );

		if ( ! $to_validate ) {
			return $response;
		}

		$validated = $this->validate_params( $to_validate, $schema_object, $method );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		foreach ( $validated as $property => $value ) {
			$this->set_request_param( $request, $property, $value );
		}

		return null;
	}

	/**
	 * Validate parameters.
	 *
	 * @since 1.0.0
	 *
	 * @param array     $to_validate
	 * @param \stdClass $schema_object
	 * @param string    $method
	 *
	 * @return array|\WP_Error
	 */
	protected function validate_params( $to_validate, $schema_object, $method ) {

		$to_validate = json_decode( wp_json_encode( $to_validate ) );
		$validator   = $this->make_validator( $method === 'POST' );

		$validator->validate( $to_validate, $schema_object );

		if ( $validator->isValid() ) {
			$return = array();

			// Validate may change the request contents based on the check mode.
			foreach ( json_decode( json_encode( $to_validate ), true ) as $prop => $value ) {
				$return[ $prop ] = $value;
			}

			return $return;
		}

		$errors         = $validator->getErrors();
		$missing_errors = array_filter( $errors, function ( $error ) { return $error['constraint'] === 'required'; } );

		$required = array();

		foreach ( $missing_errors as $missing_error ) {
			$required[] = $missing_error['property'];
		}

		if ( $required ) {
			return new \WP_Error(
				'rest_missing_callback_param',
				sprintf( __( 'Missing parameter(s): %s' ), implode( ', ', $required ) ),
				array( 'status' => 400, 'params' => $required )
			);
		}

		$invalid_params = array();

		foreach ( $validator->getErrors() as $error ) {
			$invalid_params[ $error['property'] ] = $error['message'];
		}

		return new \WP_Error(
			'rest_invalid_param',
			sprintf( __( 'Invalid parameter(s): %s' ), implode( ', ', array_keys( $invalid_params ) ) ),
			array( 'status' => 400, 'params' => $invalid_params )
		);
	}

	/**
	 * Make a Schema validator.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $is_create_request
	 * @param bool $skip_readonly
	 *
	 * @return Validator
	 */
	protected function make_validator( $is_create_request = false, $skip_readonly = true ) {
		$factory = new Factory(
			$this->schema_storage,
			$this->uri_retriever,
			$this->check_mode
		);
		$factory->setConstraintClass(
			'undefined',
			'\IronBound\WP_REST_API\SchemaValidator\UndefinedConstraint'
		);

		if ( $is_create_request ) {
			$factory->addConfig( UndefinedConstraint::CHECK_MODE_CREATE_REQUEST );
		}

		if ( $skip_readonly ) {
			$factory->addConfig( UndefinedConstraint::CHECK_MODE_SKIP_READONLY );
		}

		return new Validator( $factory );
	}

	/**
	 * Remove the default validator functions from endpoints in this namespace.
	 *
	 * @since 1.0.0
	 *
	 * @param array[] $endpoints
	 *
	 * @return array
	 */
	public function remove_default_validators_and_set_variable_schemas( array $endpoints ) {

		/** @var array $handlers */
		foreach ( $endpoints as $route => $handlers ) {
			if ( isset( $handlers['namespace'] ) && $handlers['namespace'] !== $this->namespace ) {
				continue;
			}

			if ( isset( $handlers['callback'] ) ) {
				$endpoints[ $route ] = $this->set_default_callbacks_for_handler( $handlers );

				continue;
			}

			$handlers = array_filter( $handlers, 'is_int', ARRAY_FILTER_USE_KEY );

			foreach ( $handlers as $i => $handler ) {

				if ( isset( $handler['namespace'] ) && $handler['namespace'] !== $this->namespace ) {
					continue;
				}

				$endpoints[ $route ][ $i ] = $this->set_default_callbacks_for_handler( $handler );

				// Variable schema. Move to specific option for method.
				if ( count( $handlers ) > 1 && isset( $handler['schema'] ) ) {
					$methods = is_string( $handler['methods'] ) ? explode( ',', $handler['methods'] ) : $handler['methods'];

					foreach ( $methods as $method ) {
						$endpoints[ $route ]["schema-{$method}"] = $handler['schema'];
					}
				}
			}
		}

		return $endpoints;
	}

	/**
	 * Transform an array based schema to JSON.
	 *
	 * @since 1.0.0
	 *
	 * @param array $schema
	 *
	 * @return false|string
	 */
	protected function transform_schema_to_json( array $schema ) {

		if ( ! empty( $schema['properties'] ) ) {
			foreach ( $schema['properties'] as &$property ) {
				unset( $property['arg_options'], $property['sanitize_callback'], $property['validate_callback'] );
			}
		}

		return wp_json_encode( $schema );
	}

	/**
	 * Get the URL to a schema.
	 *
	 * @since 1.0.0
	 *
	 * @param string $title The 'title' property of the schema.
	 * @param string $method
	 *
	 * @return string
	 */
	public function get_url_for_schema( $title, $method = '' ) {
		$url = rest_url( "{$this->namespace}/schemas/{$title}" );

		if ( $method ) {
			$url = urldecode_deep( add_query_arg( 'method', strtoupper( $method ), $url ) );
		}

		return $url;
	}

	/**
	 * Register the REST Route to show schemas.
	 *
	 * @since 1.0.0
	 */
	protected function register_schema_route() {
		register_rest_route( $this->namespace, '/schemas/(?P<title>\S+)', array(
			'args'     => array(
				'method' => array(
					'description' => $this->strings['methodParamDescription'],
					'type'        => 'string',
					'enum'        => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ),
				)
			),
			'methods'  => 'GET',
			'callback' => array( $this, 'get_schema_endpoint' )
		) );
	}

	/**
	 * REST endpoint for retrieving a schema.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request
	 *
	 * @return \WP_Error|\WP_REST_Response
	 */
	public function get_schema_endpoint( \WP_REST_Request $request ) {

		$title  = $request['title'];
		$schema = null;

		try {
			$url    = $this->get_url_for_schema( $title, $request['method'] );
			$schema = $this->schema_storage->getSchema( $url );
		} catch ( ResourceNotFoundException $e ) {

			if ( ! $schema ) {
				return new \WP_Error(
					'schema_not_found',
					$this->strings['schemaNotFound'],
					array( 'status' => \WP_Http::NOT_FOUND )
				);
			}
		}

		return new \WP_REST_Response( json_decode( wp_json_encode( $schema ), true ) );
	}

	/**
	 * Set the validate and sanitize callbacks to false if not set to disable WP's default validation.
	 *
	 * @since 1.0.0
	 *
	 * @param array $handler
	 *
	 * @return array
	 */
	private function set_default_callbacks_for_handler( array $handler ) {

		if ( empty( $handler['args'] ) || ! is_array( $handler['args'] ) ) {
			return $handler;
		}

		foreach ( $handler['args'] as $i => $arg ) {

			if ( empty( $arg['validate_callback'] ) || $arg['validate_callback'] === 'rest_validate_request_arg' ) {
				$arg['validate_callback'] = false;
			}

			if ( empty( $arg['sanitize_callback'] ) || $arg['sanitize_callback'] === 'rest_sanitize_request_arg' ) {
				$arg['sanitize_callback'] = false;
			}

			$handler['args'][ $i ] = $arg;
		}

		return $handler;
	}

	/**
	 * Get all endpoint configurations for this namespace.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Server $server
	 *
	 * @return array
	 */
	protected function get_endpoints_for_namespace( \WP_REST_Server $server ) {

		$routes  = $server->get_routes();
		$matched = array();

		foreach ( $routes as $route => $handlers ) {
			$options = $server->get_route_options( $route );

			if ( ! isset( $options['namespace'] ) || $options['namespace'] !== $this->namespace ) {
				continue;
			}

			$matched[ $route ] = $handlers;
		}

		return $matched;
	}

	/**
	 * Set a parameter's value on a request object.
	 *
	 * WP_REST_Request::set_param() does not properly set a value while following parameter order.
	 * See https://core.trac.wordpress.org/ticket/40344
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request
	 * @param string           $key
	 * @param mixed            $value
	 */
	protected function set_request_param( \WP_REST_Request $request, $key, $value ) {

		static $property = null;

		if ( ! $property ) {
			$reflection = new \ReflectionClass( '\WP_REST_Request' );
			$property   = $reflection->getProperty( 'params' );
			$property->setAccessible( true );
		}

		$order = array();

		$content_type = $request->get_content_type();

		if ( $content_type['value'] === 'application/json' ) {
			$order[] = 'JSON';
		}

		$accepts_body_data = array( 'POST', 'PUT', 'PATCH', 'DELETE' );

		if ( in_array( $request->get_method(), $accepts_body_data, true ) ) {
			$order[] = 'POST';
		}

		$order[] = 'GET';
		$order[] = 'URL';
		$order[] = 'defaults';

		$order = apply_filters( 'rest_request_parameter_order', $order, $request );

		$first = reset( $order );

		$params                   = $property->getValue( $request );
		$params[ $first ][ $key ] = $value;
		$property->setValue( $request, $params );
	}

	/**
	 * Convert an array to an object.
	 *
	 * @since 1.0.0
	 *
	 * @param array $array
	 *
	 * @return \stdClass
	 */
	private static function array_to_object( array $array ) {
		$obj = new \stdClass;

		foreach ( $array as $k => $v ) {
			if ( is_array( $v ) ) {
				$obj->{$k} = self::array_to_object( $v );
			} elseif ( $k !== '' ) {
				$obj->{$k} = $v;
			}
		}

		return $obj;
	}
}