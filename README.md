# WP REST API Schema Validator
Validate WP REST API requests using a complete JSON Schema validator.

WordPress ships with a validator, `rest_validate_request_arg()`, that supports a limited subset of the JSON Schema spec. This library allows the full JSON Schema spec to be used when writing endpoint schemas with minimal configuration. 

This library relies upon the [justinrainbow/json-schema](https://github.com/justinrainbow/json-schema) package to do the actual schema validation. This simply bridges the gap between the two.

## Requirements
- PHP 5.4+
- WordPress 4.5+

## Usage
Initialize a `Middleware` instance with your REST route `namespace` and an array of localized strings. This middleware should be initialized before the `rest_api_init` hook is fired. For example, `plugins_loaded`.

```php
$middleware = new \IronBound\WP_REST_API\SchemaValidator\Middleware( 'namespace/v1', [
  'methodParamDescription' => __( 'HTTP method to get the schema for. If not provided, will use the base schema.', 'text-domain' ),
  'schemaNotFound'         => __( 'Schema not found.', 'text-domain' ),
] );
$middleware->initialize();
```

That's it!
