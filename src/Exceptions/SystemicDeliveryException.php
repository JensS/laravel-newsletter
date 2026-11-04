<?php

namespace JensS\Newsletter\Exceptions;

/**
 * A failure that affects every message, not just this recipient: wrong
 * credentials, sending quota exceeded, server unreachable. Trips the circuit
 * breaker so that sending pauses instead of failing the whole list.
 */
class SystemicDeliveryException extends TemporaryDeliveryException {}
