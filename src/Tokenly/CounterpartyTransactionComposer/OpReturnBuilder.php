<?php

namespace Tokenly\CounterpartyTransactionComposer;

use Tokenly\CounterpartyTransactionComposer\Quantity;
use \Exception;

/*
* OpReturnBuilder
*/
class OpReturnBuilder
{

    const SATOSHI = 100000000;

    public function buildOpReturn($raw_amount, $asset, $txid) {
        // normalize $raw_amount
        if ($raw_amount instanceof Quantity) {
            $amount = dechex(round($raw_amount->getValueForCounterpartyRPC()));
        } else {
            $amount = dechex(round($raw_amount * self::SATOSHI));
        }


        // construct the op_return data
        $prefix_hex = '434e545250525459'; // CNTRPRTY
        $type_hex   = '00000000';
        $asset_hex  = str_pad($this->assetNameToIDHex($asset), 16, '0', STR_PAD_LEFT);
        $amount_hex = str_pad($amount, 16, '0', STR_PAD_LEFT);
        $unobfuscated_op_return = $prefix_hex.$type_hex.$asset_hex.$amount_hex;

        // obfuscate with ARC4
        $arc4_key = $txid;
        $op_return = bin2hex($this->arc4encrypt(hex2bin($arc4_key), hex2bin($unobfuscated_op_return)));

        return $op_return;
    }

    protected function arc4encrypt($key, $plain_text) {
        $init_vector = '';
        return @mcrypt_encrypt(MCRYPT_ARCFOUR, $key, $plain_text, MCRYPT_MODE_STREAM, $init_vector);
    }

    protected function assetNameToIDHex($asset_name) {
        $n = gmp_init(0);
        for ($i=0; $i < strlen($asset_name); $i++) {
            $n = gmp_mul($n, 26);
            $char = ord(substr($asset_name, $i, 1));
            if ($char < 65 OR $char > 90) { throw new Exception("Asset name invalid", 1); }
            $digit = $char - 65;
            $n = gmp_add($n, $digit);
        }
        
        return gmp_strval($n, 16);
    }


}

