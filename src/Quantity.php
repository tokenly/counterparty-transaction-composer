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
    protected $is_divisible_asset;

    public static function individisibleAssetQuantity($float_value) {
        return new self($float_value, false);
    }

    function __construct($float_value, $is_divisible_asset=true) {
        $this->float_value  = $float_value;
        $this->is_divisible_asset = $is_divisible_asset;
    }

    public function getValueForCounterpartyRPC() {
        if ($this->is_divisible_asset) {
            // divisible - convert to satoshis
            return $this->getSatoshis();
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

    public function setIsDivisible($is_divisible_asset) { $this->is_divisible_asset = !!$is_divisible_asset; }
    public function isDivisible() { return $this->is_divisible_asset; }
}

