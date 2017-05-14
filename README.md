# WP REST API Schema Validator
Validate WP REST API requests using a complete JSON Schema validator.

WordPress ships with a validator, `rest_validate_request_arg()`, that supports a limited subset of the JSON Schema spec. This library allows the full JSON Schema spec to be used when writing endpoint schemas with minimal configuration. 

This library relies upon the [justinrainbow/json-schema](https://github.com/justinrainbow/json-schema) package to do the actual schema validation. This simply bridges the gap between the two.

## Requirements
- PHP 5.4+
- WordPress 4.5+

## Installation
`composer require ironbound/wp-rest-api-schema-validator`

## Usage
Initialize a `Middleware` instance with your REST route `namespace` and an array of localized strings. This middleware should be initialized before the `rest_api_init` hook is fired. For example, `plugins_loaded`.

Additionally, schemas must be created with a `title` attribute on the top level. This title should be unique within the versioned namespace.

```php
$middleware = new \IronBound\WP_REST_API\SchemaValidator\Middleware( 'namespace/v1', [
  'methodParamDescription' => __( 'HTTP method to get the schema for. If not provided, will use the base schema.', 'text-domain' ),
  'schemaNotFound'         => __( 'Schema not found.', 'text-domain' ),
] );
$middleware->initialize();
```

That's it!

## Advanced

### GET and DELETE Requests

Query parameters passed with GET or DELETE requests are validated against the `args` option that is passed when registering the route.

### Technical Details
 
On `rest_api_init#100`, the middleware will iterate over the registered routes in the provided namespace. The default WordPress core validation and sanitization functions will be disabled. 

Schema validation will be performed on the `rest_dispatch_request#10` hook.

`WP_Error` objects will be returned that match the format in `WP_REST_Request`. Mainly, an error code of `rest_missing_callback_param` or `rest_invalid_param`, a `400` response status code, and detailed error information in `data.params`. 

For missing parameters, `data.params` will contain a list of the missing parameter names. For invalid parameters,
a map of parameter names to a specific validation error message.

### Procedural Validation
In the vast majority of cases, validation should be configured using JSON Schema definitions. However, this is not always the case. For example, verifying that a username is not taken requires making calls to the database that would be impossible to replicate in the schema definition. In these cases, a `validate_callback` can still be provided and will be executed before JSON Schema validation takes place.

```php
return [
    '$schema'    => 'http://json-schema.org/schema#',
    'title'      => 'users',
    'type'       => 'object',
    'properties' => [
        'username' => [
            'description' => __( 'Login name for the user.', 'text-domain' ),
            'type'        => 'string',
            'context'     => [ 'view', 'edit', 'embed' ],
            'arg_options' => [
                'validate_callback' => function( $value ) {
                    return ! username_exists( $value );
                },
            ],   
        ],
    ],
];
```

### Variable Schemas
In most cases, the schema document should be the same for all HTTP methods on a given endpoint. In the rare case that a separate schema document is provided, a `schema` option can be provided to the route args for that HTTP method. The `title` for the separate schema document MUST be the same as the base schema.

```php
register_rest_route( 'namespace/v1', 'route', [
    [
        'methods'  => 'GET',
        'callback' => [ $this, 'get_item' ],
        'args'     => $this->get_endpoint_args_for_item_schema( 'GET' ),
    ],
    [
        'methods'  => 'POST',
        'callback' => array( $this, 'create_item' ),
         // See WP_REST_Controller::get_endpoint_args_for_item_schema() for reference.
        'args'     => $this->get_endpoint_args_for_post_schema(),
        'schema'   => [ $this, 'get_public_item_post_schema' ],
    ],
    [
        'methods'  => 'PUT',
        'callback' => [ $this, 'update_item' ],
        'args'     => $this->get_endpoint_args_for_item_schema( 'PUT' ),
    ],
    'schema' => [ $this, 'get_public_item_schema' ],
] );
```

### Reusing Schemas
JSON Schema provides a mechanism to utilize a referenced Schema document for validation. This package allows you to accomplish this by using the `Middleware::get_url_for_schema( $title )` method.

For example, this Schema will validate the `card` property according to the Schema document with the title `card`.
```php
[
    '$schema'    => 'http://json-schema.org/schema#',
    'title'      => 'transaction',
    'type'       => 'object',
    'properties' => [
        'card' => [
            '$ref' => $middleware->get_url_for_schema( 'card' )   
        ],
    ],
];
```

But what if there is no `/cards` route? Or a more general schema is required? In this case, a shared schema can be used.
```php
$middleware->add_shared_schema( [
    '$schema'    => 'http://json-schema.org/schema#',
    'title'      => 'card',
    'type'       => 'object',
    'properties' => [
        'card_number' => [
            'type'    => 'string',
            'pattern' => '^[0-9]{11,19}$',
        ],
        'exp_year'  => [ 'type' => 'integer' ],
        'exp_month' => [ 
            'type' => 'integer',
            'minimum' => 1,
            'maximum' => 12,
         ],
    ],
] );
```

### Schema Routes

After all routes have been registered, the middleware will register its own route.
 
```
namespace/v1/schemas/(?P<title>[\S+])
``` 

This route returns the plain schema document for the given title. To retrieve a schema for a given HTTP method, pass the desired upper-cased HTTP method to the `method` query param.

```HTTP
GET https://example.org/wp-json/namespace/v1/schemas/transaction?method=POST
```