<?php
/**
 * Class Lazy Retriever.
 *
 * @author      Iron Bound Designs
 * @since       1.0
 * @copyright   2018 (c) Iron Bound Designs.
 * @license     GPLv2
 */

namespace IronBound\WP_REST_API\SchemaValidator;

use JsonSchema\Uri\Retrievers\AbstractRetriever;
use JsonSchema\Validator;

/**
 * Class LazyRetriever
 *
 * @package IronBound\WP_REST_API\SchemaValidator
 */
class LazyRetriever extends AbstractRetriever {

	/**
	 * Contains schema resolvers as URI => Callable.
	 *
	 * @var callable[]
	 */
	private $callables;

	/**
	 * Contains schemas as URI => JSON
	 *
	 * @var array
	 */
	private $schemas = array();

	/**
	 * Constructor
	 *
	 * @param callable[] $schemas
	 * @param string     $contentType
	 */
	public function __construct( array $callables, $contentType = Validator::SCHEMA_MEDIA_TYPE ) {
		$this->callables   = $callables;
		$this->contentType = $contentType;
	}

	/**
	 * Add a callable registration.
	 *
	 * @param string   $uri
	 * @param callable $callable
	 */
	public function add_callable( $uri, $callable ) {
		if ( is_callable( $callable ) ) {
			$this->callables[ $uri ] = $callable;
		}
	}

	/**
	 * Add a Schema entry.
	 *
	 * @param string $uri
	 * @param string $schema
	 */
	public function add_schema( $uri, $schema ) {
		$this->schemas[ $uri ] = $schema;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @see \JsonSchema\Uri\Retrievers\UriRetrieverInterface::retrieve()
	 */
	public function retrieve( $uri ) {
		if ( ! array_key_exists( $uri, $this->callables ) && ! array_key_exists( $uri, $this->schemas ) ) {
			throw new \JsonSchema\Exception\ResourceNotFoundException( sprintf(
				'The JSON schema "%s" was not found.',
				$uri
			) );
		}

		if ( ! isset( $this->schemas[ $uri ] ) ) {

			$schema = call_user_func( $this->callables[ $uri ] );

			if ( ! empty( $schema['properties'] ) ) {
				foreach ( $schema['properties'] as &$property ) {
					unset( $property['arg_options'], $property['sanitize_callback'], $property['validate_callback'] );
				}
			}

			$this->schemas[ $uri ] = wp_json_encode( $schema );
		}

		return $this->schemas[ $uri ];
	}
}