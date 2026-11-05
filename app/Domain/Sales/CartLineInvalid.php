<?php

namespace App\Domain\Sales;

use RuntimeException;

/** A single cart line failed validation; CartPricer reports it against that line. */
class CartLineInvalid extends RuntimeException {}
