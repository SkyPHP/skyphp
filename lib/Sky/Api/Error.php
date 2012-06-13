<?php

namespace Sky\Api;

/**
 * Standard format for an Error in a \Sky\Api\Response
 */
class Error extends \ValidationError
{

}

/**
  * Throw this and the REST API will output a 400 response
  */
class ValidationException extends \ValidationException
{

}

/**
  * Throw this and the REST API will output a 403 response
  */
class AccessDeniedException extends \Exception
{

}

/**
 * Throw this and the REST API will output a 404 response
 */
class NotFoundException extends \Exception
{

}
