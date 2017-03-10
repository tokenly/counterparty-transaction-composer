<?php

namespace Tokenly\CounterpartyTransactionComposer;

use \Exception;

/*
* ComposedTransaction
*/
class ComposedTransaction
{

    const SATOSHI = 100000000;

    protected $txid;
    protected $hex;
    protected $input_utxos;
    protected $output_utxos;

    function __construct($txid, $hex, $input_utxos, $output_utxos, $signed) {
        $this->txid         = $txid;
        $this->hex          = $hex;
        $this->input_utxos  = $input_utxos;
        $this->output_utxos = $output_utxos;
        $this->signed       = $signed;
    }


    public function getTxId() { return $this->txid; }
    public function getTransactionHex() { return $this->hex; }
    public function getInputUtxos() { return $this->input_utxos; }
    public function getOutputUtxos() { return $this->output_utxos; }
    public function getSigned() { return $this->signed; }


    public function feeSatoshis() {
        $in = 0;
        foreach ($this->input_utxos as $utxo) {
            $in += $utxo['amount'];
        }

        $out = 0;
        foreach ($this->output_utxos as $utxo) {
            $out += $utxo['amount'];
        }

        return $in - $out;
    }
    public function feeFloat() {
        return round($this->feeSatoshis() / self::SATOSHI, 8);
    }

    public function getSize() {
        return strlen($this->getTransactionHex()) / 2;
    }

    public function getSatoshisPerByte() {
        return ceil($this->feeSatoshis() / $this->getSize());
    }

}