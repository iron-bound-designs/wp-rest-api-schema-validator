<?php
/**
 * Extended Undefined Constraint to add support for readonly and createonly.
 *
 * @author      Iron Bound Designs
 * @since       1.0
 * @copyright   2017 (c) Iron Bound Designs.
 * @license     MIT
 */

namespace IronBound\WP_REST_API\SchemaValidator;

use JsonSchema\Entity\JsonPointer;

/**
 * Class UndefinedConstraint
 *
 * @package IronBound\WP_REST_API\SchemaValidator
 */
class UndefinedConstraint extends \JsonSchema\Constraints\UndefinedConstraint {

	const CHECK_MODE_SKIP_READONLY = 0x1000000;
	const CHECK_MODE_CREATE_REQUEST = 0x2000000;

	/**
	 * @inheritdoc
	 */
	public function check( &$value, $schema = null, JsonPointer $path = null, $i = null, $fromDefault = false ) {

		if ( is_null( $schema ) || ! is_object( $schema ) ) {
			return;
		}

		if ( $this->factory->getConfig( self::CHECK_MODE_SKIP_READONLY ) && ( ! empty( $schema->readonly ) || ! empty( $schema->readOnly ) ) ) {
			$value = null;

			return;
		}

		// If this is not a create request and the property is marked as createOnly, then skip validation for it.
		if ( ! $this->factory->getConfig( self::CHECK_MODE_CREATE_REQUEST ) && ( ! empty( $schema->createonly ) || ! empty( $schema->createOnly ) ) ) {
			$value = null;

			return;
		}

		return parent::check( $value, $schema, $path, $i, $fromDefault );
	}

}