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
use JsonSchema\Entity\JsonPointer;
use JsonSchema\Exception\ResourceNotFoundException;
use JsonSchema\Iterator\ObjectIterator;
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

	/** @var array */
	private $options;

	/** @var array[] */
	private $shared_schemas = array();

	/** @var UriRetriever */
	private $uri_retriever;

	/** @var SchemaStorage */
	private $schema_storage;

	/** @var array[] '/wp/v2/posts' => [ 'GET' => 'posts', 'POST' => 'http://...', 'PUT' => 'http://...' ] */
	private $routes_to_schema_urls = array();

	/**
	 * Middleware constructor.
	 *
	 * @param string $namespace
	 * @param array  $strings
	 * @param int    $check_mode Check mode. See Constraint class constants.
	 * @param array  $options    Additional options to customize how the middleware behaves.
	 */
	public function __construct( $namespace, array $strings = array(), $check_mode = 0, array $options = [] ) {
		$this->namespace = trim( $namespace, '/' );
		$this->strings   = wp_parse_args( $strings, array(
			'methodParamDescription' => 'HTTP method to get the schema for. If not provided, will use the base schema.',
			'schemaNotFound'         => 'Schema not found.',
			'expandSchema'           => 'Expand $ref schemas.',
		) );

		if ( $check_mode === 0 ) {
			$check_mode = Constraint::CHECK_MODE_NORMAL | Constraint::CHECK_MODE_APPLY_DEFAULTS | Constraint::CHECK_MODE_COERCE_TYPES | Constraint::CHECK_MODE_TYPE_CAST;
		}

		$this->check_mode = $check_mode;
		$this->options    = $options;
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
		$schemas        = $callables = array();
		$urls_by_method = array();

		foreach ( $endpoints as $route => $handlers ) {

			$options = $server->get_route_options( $route );

			if ( empty( $options['schema'] ) ) {
				if ( empty( $handlers[0]['schema'] ) ) {
					continue;
				}

				$callable = $handlers[0]['schema'];
				$title    = isset( $handlers[0]['schema-title'] ) ? $handlers[0]['schema-title'] : '';
			} else {
				$callable = $options['schema'];
				$title    = isset( $options['schema-title'] ) ? $options['schema-title'] : '';
			}

			if ( $title ) {
				$schema = null;
			} else {
				$schema = call_user_func( $callable );

				if ( empty( $schema['title'] ) ) {
					continue;
				}

				$title = $schema['title'];
			}

			$uri = $this->get_url_for_schema( $title );

			$urls_by_method[ $route ] = array();

			if ( $schema ) {
				$schemas[ $uri ] = wp_json_encode( $schema );
			} else {
				$callables[ $uri ] = $callable;
			}

			if ( isset( $handlers['callback'] ) ) {
				$handlers = array( $handlers );
			}

			// Allow for different schemas per HTTP Method.
			foreach ( $handlers as $i => $handler ) {
				foreach ( $handler['methods'] as $method => $_ ) {

					if ( ! isset( $options["schema-{$method}"] ) ) {
						$urls_by_method[ $route ][ $method ] = $uri;

						continue;
					}

					$method_schema_cb = $options["schema-{$method}"];
					$method_schema    = null;

					if ( isset( $options["schema-title-{$method}"] ) ) {
						$method_title = $options["schema-title-{$method}"];
					} else {
						$method_schema = call_user_func( $method_schema_cb );

						if ( empty( $method_schema['title'] ) ) {
							continue;
						}

						$method_title = $method_schema['title'];
					}

					$method_uri = $this->get_url_for_schema( $title, $method );

					if ( $method_schema ) {
						$schemas[ $method_uri ] = wp_json_encode( $method_schema );
					} else {
						$callables[ $method_uri ] = $method_schema_cb;
					}

					$urls_by_method[ $route ][ $method ] = $method_uri;

					if ( $method_title !== $title ) {
						$alt_method_uri = $this->get_url_for_schema( $method_title );

						if ( $method_schema ) {
							$schemas[ $alt_method_uri ] = wp_json_encode( $method_schema );
						} else {
							$callables[ $alt_method_uri ] = $method_schema_cb;
						}
					}
				}
			}
		}

		$strategy = new LazyRetriever( $callables );

		foreach ( $this->shared_schemas as $shared_schema ) {
			$strategy->add_schema( $this->get_url_for_schema( $shared_schema['title'] ), wp_json_encode( $shared_schema ) );
		}

		foreach ( $schemas as $uri => $schema ) {
			$strategy->add_schema( $uri, $schema );
		}

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

		if ( strpos( trim( $route, '/' ), $this->namespace ) !== 0 ) {
			return $response;
		}

		$method = $request->get_method();

		if ( $method === 'PATCH' ) {
			$patch_get_info = $this->get_validate_info_for_method( 'GET', $route, $handler );
			$validated      = $this->validate_and_conform_for_method( $request, $patch_get_info['schema'], 'GET' );

			if ( is_wp_error( $validated ) ) {
				return $validated;
			}
		}

		$info = $this->get_validate_info_for_method( $method, $route, $handler );

		if ( ! $info ) {
			return $response;
		}

		if ( ! empty( $info['described'] ) ) {
			$this->add_described_by( $request, $info['described'] );
		}

		return $this->validate_and_conform_for_method( $request, $info['schema'], $method );
	}

	/**
	 * Get the schema to use for a given method.
	 *
	 * @param string $method
	 * @param string $route
	 * @param array  $handler
	 *
	 * @return array
	 */
	protected function get_validate_info_for_method( $method, $route, $handler ) {
		$map = $this->routes_to_schema_urls;

		if ( $method === 'GET' || $method === 'DELETE' ) {
			$schema_object = json_decode( $this->transform_schema_to_json( array(
				'type'       => 'object',
				'properties' => $handler['args'],
			) ) );
			$described_by  = isset( $map[ $route ], $map[ $route ][ $method ] ) ? $map[ $route ][ $method ] : null;
		} elseif ( isset( $map[ $route ], $map[ $route ][ $method ] ) ) {
			$schema_object = clone $this->schema_storage->getSchema( $map[ $route ][ $method ] );
			$described_by  = $map[ $route ][ $method ];
		} else {
			return array();
		}

		return array(
			'schema'    => $schema_object,
			'described' => $described_by,
		);
	}


	/**
	 * Conform the request or return an error.
	 *
	 * @param \WP_REST_Request $request
	 * @param object           $schema
	 * @param string           $method
	 *
	 * @return null|\WP_Error
	 */
	public function validate_and_conform_for_method( $request, $schema, $method ) {

		$to_validate = $this->get_params_to_validate( $request, $method );

		/*if ( ! $to_validate ) {
			return null;
		}*/

		$validated = $this->validate_params( $to_validate, $schema, $method );

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$this->update_request_params( $request, $validated );

		return null;
	}

	/**
	 * Get the parameters that we should be validating.
	 *
	 * @param \WP_REST_Request $request
	 * @param string           $method
	 *
	 * @return array
	 */
	protected function get_params_to_validate( $request, $method ) {

		$defaults = $request->get_default_params();
		$request->set_default_params( array() );

		if ( $request->get_method() === 'PATCH' && $method === 'PATCH' ) {
			$to_validate = $request->get_json_params() ?: $request->get_body_params();
		} elseif ( $request->get_method() === 'PATCH' && $method === 'GET' ) {
			$to_validate = $request->get_query_params();
		} elseif ( ! empty( $this->options['strict_body'] ) && ( $request->get_method() === 'POST' || $request->get_method() === 'PUT' ) ) {
			$to_validate = $request->get_json_params() ?: $request->get_body_params();
		} else {
			$to_validate = $request->get_params();

			foreach ( $request->get_url_params() as $param => $value ) {
				unset( $to_validate[ $param ] );
			}
		}

		$request->set_default_params( $defaults );

		return $to_validate;
	}

	/**
	 * Update the params on a request object.
	 *
	 * @param \WP_REST_Request $request
	 * @param array            $validated
	 */
	protected function update_request_params( $request, $validated ) {

		$defaults = $request->get_default_params();
		$request->set_default_params( array() );

		foreach ( $validated as $property => $value ) {

			if ( $value === null && $request[ $property ] !== null ) {
				unset( $request[ $property ] );
				continue;
			}

			if ( $value === $request->get_param( $property ) ) {
				continue;
			}

			$this->set_request_param( $request, $property, $value );
		}

		$request->set_default_params( $defaults );
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
			$invalid_params[ $error['property'] ?: '#' ] = $error['message'];
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
		$factory->setConstraintClass(
			'format',
			'\IronBound\WP_REST_API\SchemaValidator\FormatConstraint'
		);
		$factory->setConstraintClass(
			'type',
			'\IronBound\WP_REST_API\SchemaValidator\TypeConstraint'
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
	 * Add the described by header.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_REST_Request $request
	 * @param string           $described_by
	 */
	protected function add_described_by( \WP_REST_Request $request, $described_by ) {

		if ( ! $described_by ) {
			return;
		}

		add_filter( 'rest_post_dispatch', $fn = function ( $response, $_, $_request ) use ( $request, $described_by, &$fn ) {

			if ( $request !== $_request ) {
				return $response;
			}

			if ( $response instanceof \WP_REST_Response ) {
				$response->link_header( 'describedby', $described_by );
			}

			remove_filter( 'rest_post_dispatch', $fn );

			return $response;
		}, 10, 3 );
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

						if ( isset( $handler['schema-title'] ) ) {
							$endpoints[ $route ]["schema-title-{$method}"] = $handler['schema-title'];
						}
					}
				} elseif ( isset( $handler['schema'] ) ) {
					// Have the per-route schema overwrite the main schema.
					$endpoints[ $route ]['schema'] = $handler['schema'];
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
				),
				'expand' => array(
					'description' => $this->strings['expandSchema'],
					'type'        => 'boolean',
				),
			),
			'methods'  => 'GET',
			'callback' => array( $this, 'get_schema_endpoint' ),
			'permission_callback'  => '__return_true',
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
		$method = $request['method'];

		$try = array( $this->get_url_for_schema( $title ) );

		if ( $method ) {
			$try[] = $this->get_url_for_schema( $title, $method );
		}

		foreach ( array_reverse( $try ) as $url ) {
			try {
				$schema = $this->schema_storage->getSchema( $url );

				if ( $request['expand'] ) {
					$schema = $this->expand( $schema );
				}
				break;
			} catch ( ResourceNotFoundException $e ) {

			}
		}

		if ( ! $schema ) {
			return new \WP_Error(
				'schema_not_found',
				$this->strings['schemaNotFound'],
				array( 'status' => \WP_Http::NOT_FOUND )
			);
		}

		$response = new \WP_REST_Response( $this->clean_schema( json_decode( wp_json_encode( $schema ), true ) ) );

		foreach ( $this->routes_to_schema_urls as $path => $urls ) {
			foreach ( $urls as $maybe_url ) {
				if ( $maybe_url === $url ) {
					$template = $this->convert_regex_route_to_uri_template( rest_url( $path ) );
					$response->link_header( 'describes', $template );
					break 2;
				}
			}
		}

		return $response;
	}

	/**
	 * Clean a schema of any arg_options.
	 *
	 * @param array $schema
	 *
	 * @return array
	 */
	protected function clean_schema( $schema ) {

		if ( is_array( $schema ) ) {
			foreach ( $schema as $key => $value ) {
				if ( is_array( $value ) ) {
					unset( $value['arg_options'] );
					$schema[ $key ] = $this->clean_schema( $value );
				}
			}
		}

		return $schema;
	}

	/**
	 * Expand $ref schemas.
	 *
	 * @since 1.0.0
	 *
	 * @param \stdClass $schema
	 *
	 * @return \stdClass
	 */
	protected function expand( $schema ) {

		foreach ( $schema as $i => $sub_schema ) {
			if ( is_object( $sub_schema ) && property_exists( $sub_schema, '$ref' ) && is_string( $sub_schema->{'$ref'} ) ) {
				$schema->{$i} = $this->schema_storage->resolveRefSchema( $sub_schema );
			} elseif ( is_object( $sub_schema ) ) {
				$schema->{$i} = $this->expand( $sub_schema );
			}
		}

		return $schema;
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

		if ( $content_type === null || $content_type['value'] === 'application/json' ) {
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

		$params    = $property->getValue( $request );
		$found_key = false;

		foreach ( $order as $type ) {
			if ( isset( $params[ $type ][ $key ] ) ) {
				$params[ $type ][ $key ] = $value;
				$found_key               = true;
				break;
			}
		}

		if ( ! $found_key ) {
			$params[ $order[0] ][ $key ] = $value;
		}

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

	/**
	 * Convert a regex based route to one that follows the URI template standard.
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	private function convert_regex_route_to_uri_template( $url ) {
		return preg_replace( '/\(.[^<*]<(\w+)>[^<.]*\)/', '{$1}', $url );
	}
}
