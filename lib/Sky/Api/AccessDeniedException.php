<?php

namespace Sky\Api;

/**
 * Throw this and the REST API will output a 403 response
 */
class AccessDeniedException extends \Exception {}
