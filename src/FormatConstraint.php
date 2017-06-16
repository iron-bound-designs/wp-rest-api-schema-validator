<?php
/**
 * Extended Format Constraint to add support for 'html' format.
 *
 * @author      Iron Bound Designs
 * @since       1.0
 * @copyright   2017 (c) Iron Bound Designs.
 * @license     MIT
 */

namespace IronBound\WP_REST_API\SchemaValidator;

use JsonSchema\Entity\JsonPointer;

/**
 * Class FormatConstraint
 *
 * @package IronBound\WP_REST_API\SchemaValidator
 */
class FormatConstraint extends \JsonSchema\Constraints\FormatConstraint {

	/**
	 * @inheritDoc
	 */
	public function check( &$element, $schema = null, JsonPointer $path = null, $i = null ) {

		if ( ! isset( $schema->format ) || $this->factory->getConfig( self::CHECK_MODE_DISABLE_FORMAT ) ) {
			return;
		}

		if ( $schema->format === 'html' ) {
			$allowed = isset( $schema->formatAllowedHtml ) ? $schema->formatAllowedHtml : array();

			if ( ! $this->validateHtml( $element, $allowed ) ) {
				$this->addError( $path, 'Invalid html', 'format', array( 'format' => $schema->format ) );
			}
		}

		return parent::check( $element, $schema, $path, $i );
	}

	protected function validateHtml( $html, array $allowed_html_tags = array() ) {

		global $allowedposttags, $allowedtags;

		if ( $allowed_html_tags ) {
			$kses_format = array();

			foreach ( $allowed_html_tags as $tag ) {
				if ( isset( $allowedposttags[ $tag ] ) ) {
					$kses_format[ $tag ] = $allowedposttags[ $tag ];
				}
			}
		} else {
			$kses_format = $allowedtags;
		}

		return trim( $html ) === trim( wp_kses( $html, $kses_format ) );
	}
}