<?php

namespace Tokenly\CounterpartyTransactionComposer;

use \Exception;

/*
* Quantity
*/
class Quantity
{

    const SATOSHI = 100000000;

    protected $float_value;
    protected $is_divisible;

    public static function newIndivisible($float_value) {
        return new self($float_value, false);
    }

    function __construct($float_value, $is_divisible=true) {
        $this->float_value  = $float_value;
        $this->is_divisible = $is_divisible;
    }

    public function getValueForCounterpartyRPC() {
        if ($this->is_divisible) {
            // divisible - convert to satoshis
            return intval(round($this->float_value * self::SATOSHI));
        } else {
            // not divisible - do not use satoshis
            return intval(round($this->float_value));
        }
    }

    public function getSatoshis() {
        return intval(round($this->float_value * self::SATOSHI));
    }

    public function setValue($float_value) { $this->float_value = $float_value; }
    public function getRawValue() { return $this->float_value; }

    public function setIsDivisible($is_divisible) { $this->is_divisible = !!$is_divisible; }
    public function isDivisible() { return $this->is_divisible; }
}

