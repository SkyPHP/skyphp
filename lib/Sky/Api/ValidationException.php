<?php

namespace Sky\Api;

/**
 * Throw this and the REST API will output a 400 response
 */
class ValidationException extends \ValidationException {}
