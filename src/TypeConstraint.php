<?php
/**
 * Extended Types Constraint to add support for coercing "1" to true.
 *
 * @author      Iron Bound Designs
 * @since       1.0
 * @copyright   2018 (c) Iron Bound Designs.
 * @license     MIT
 */

namespace IronBound\WP_REST_API\SchemaValidator;

/**
 * Class TypeConstraint
 *
 * @package IronBound\WP_REST_API\SchemaValidator
 */
class TypeConstraint extends \JsonSchema\Constraints\TypeConstraint {

	/**
	 * @inheritDoc
	 */
	protected function toBoolean( $value ) {

		if ( $value === "1" ) {
			return true;
		}

		if ( $value === "0" ) {
			return false;
		}

		return parent::toBoolean( $value );
	}
}