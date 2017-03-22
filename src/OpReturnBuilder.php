<?php

namespace Tokenly\CounterpartyTransactionComposer;

use Tokenly\CounterpartyTransactionComposer\Quantity;
use Tokenly\CryptoQuantity\CryptoQuantity;
use \Exception;

/*
* OpReturnBuilder
*/
class OpReturnBuilder
{

    const SATOSHI = 100000000;

    // deprecated - use buildOpReturnForSend
    public function buildOpReturn($raw_amount, $asset, $txid) {
        return $this->buildOpReturnForSend($raw_amount, $asset, $txid);
    }

    public function buildOpReturnForSend($raw_amount, $asset, $txid) {
        $amount_hex = $this->paddedRawAmountHex($raw_amount);
        $asset_hex  = $this->padHexString($this->assetNameToIDHex($asset), 8);
        $data_hex = $asset_hex.$amount_hex;

        return $this->assembleCounterpartyOpReturn(0, $data_hex, $txid);
    }

    // description should be 41 characters or less
    public function buildOpReturnForIssuance($raw_amount, $asset, $divisible, $description, $txid) {
        $amount_hex          = $this->paddedRawAmountHex($raw_amount);
        $asset_hex           = $this->padHexString($this->assetNameToIDHex($asset), 8);
        $divisible_hex       = $this->padHexString(($divisible ? 1 : 0), 1);
        $call_data_hex       = '000000000000000000';
        $description_hex     = unpack('H*0', $description)[0];
        $description_len_hex = $this->padHexString(dechex(strlen($description_hex) / 2), 1);
        $data_hex = $asset_hex.$amount_hex.$divisible_hex.$call_data_hex.$description_len_hex.$description_hex;

        // must be 80 bytes to fit into OP_RETURN
        if (strlen($data_hex) > 160) { throw new Exception("Description too long", 1); }

        return $this->assembleCounterpartyOpReturn(20, $data_hex, $txid);
    }

    protected function assembleCounterpartyOpReturn($type_id, $data_hex, $txid) {
        // construct the op_return data
        $prefix_hex = '434e545250525459'; // CNTRPRTY
        $type_hex   = $this->padHexString(dechex($type_id), 4);
        $unobfuscated_op_return = $prefix_hex.$type_hex.$data_hex;

        // obfuscate with ARC4
        if ($txid === null) {
            $op_return = $unobfuscated_op_return;
        } else {
            $arc4_key = $txid;
            $op_return = bin2hex($this->arc4encrypt(hex2bin($arc4_key), hex2bin($unobfuscated_op_return)));
        }

        return $op_return;
    }

    protected function paddedRawAmountHex($raw_amount) {
        // normalize $raw_amount
        if ($raw_amount instanceof CryptoQuantity) {
            $amount = dechex(round($raw_amount->getValueForCounterparty()));
        } else if ($raw_amount instanceof Quantity) {
            $amount = dechex(round($raw_amount->getValueForCounterpartyRPC()));
        } else {
            $amount = dechex(round($raw_amount * self::SATOSHI));
        }

        return $this->padHexString($amount, 8);
    }

    protected function padHexString($hex_string, $bytes) {
        return str_pad($hex_string, $bytes * 2, '0', STR_PAD_LEFT);
    }

    protected function arc4encrypt($key, $plain_text) {
        $init_vector = '';
        return @mcrypt_encrypt(MCRYPT_ARCFOUR, $key, $plain_text, MCRYPT_MODE_STREAM, $init_vector);
    }

    protected function assetNameToIDHex($asset_name) {
        if ($asset_name == 'BTC') { return '0'; }
        if ($asset_name == 'XCP') { return '1'; }

        if (substr($asset_name, 0, 1) == 'A') {
            // numerical asset
            // An integer between 26^12 + 1 and 256^8 (inclusive)
            $asset_id = gmp_init(substr($asset_name, 1));
            if (!preg_match('!^\\d+$!', $asset_id)) { throw new Exception("Invalid asset ID", 1); }
            if ($asset_id < gmp_init(26)**12 + 1) { throw new Exception("Asset ID was too low", 1); }
            if ($asset_id > gmp_init(2)**64 - 1) { throw new Exception("Asset ID was too high", 1); }

            return gmp_strval($asset_id, 16);
        }

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

