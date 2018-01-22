<?php namespace Larabookir\Gateway\Asanpardakht;

use DateTime;
use Illuminate\Support\Facades\Intput;
use Larabookir\Gateway\Enum;
use SoapClient;
use Larabookir\Gateway\PortAbstract;
use Larabookir\Gateway\PortInterface;
use Larabookir\Gateway\Asanpardakht\AES;

class Asanpardakht extends PortAbstract implements PortInterface
{
	/**
	 * Address of main SOAP server
	 *
	 * @var string
	 */
	protected $serverUrl = 'https://services.asanpardakht.net/paygate/merchantservices.asmx';

	/**
	 * {@inheritdoc}
	 */
	public function set($amount)
	{
		$this->amount = $amount;

		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function ready()
	{
		$this->sendPayRequest();

		return $this;
	}

	/**
	 * {@inheritdoc}
	 */
	public function redirect()
	{
		$refId = $this->refId;

		return view('gateway::asanpardakht-redirector')->with(compact('refId'));
	}

	/**
	 * {@inheritdoc}
	 */
	public function verify($transaction)
	{
		parent::verify($transaction);

		$this->userPayment();
		$this->verifyPayment();
		$this->completePayment();

		return $this;
	}

	/**
	 * Sets callback url
	 * @param $url
	 */
	function setCallback($url)
	{
		$this->callbackUrl = $url;
		return $this;
	}

	/**
	 * Gets callback url
	 * @return string
	 */
	function getCallback()
	{
		if (!$this->callbackUrl)
			$this->callbackUrl = $this->config->get('gateway.asanpardakht.callback-url');

		return $this->makeCallback($this->callbackUrl, ['transaction_id' => $this->transactionId()]);
	}

	/**
	 * Send pay request to server
	 *
	 * @return void
	 *
	 * @throws AsanpardakhtException
	 */
	protected function sendPayRequest()
	{
		$dateTime = new DateTime();

		$this->newTransaction();

		$fields = array(1
            ,$this->config->get('gateway.asanpardakht.username')
            ,$this->config->get('gateway.asanpardakht.password')
            ,$this->transactionId()
            ,$this->amount
            ,$dateTime->format('YYYYMMDD HHMMSS')
            ,''
			,$this->getCallback()
            ,0
		);
		$data = implode(',',$fields);
		$AES = new AES([
		    $this->config->get('gateway.asanpardakht.key'),
		    $this->config->get('gateway.asanpardakht.iv'),
		    $this->config->get('gateway.asanpardakht.username'),
		    $this->config->get('gateway.asanpardakht.password')
                ]);
        $string =  $AES->encrypt($data);

		try {
			$soap = new SoapClient($this->serverUrl);
			$response = $soap->RequestOperation($this->config->get('gateway.asanpardakht.merchant_id') , $string);

		} catch (\SoapFault $e) {
			$this->transactionFailed();
			$this->newLog('SoapFault', $e->getMessage());
			throw $e;
		}

		$response = explode(',', $response->return);

		if ($response[0] != '0') {
			$this->transactionFailed();
			$this->newLog($response[0], AsanpardakhtException::$errors[$response[0]]);
			throw new AsanpardakhtException($response[0]);
		}
		$this->refId = $response[1];
		$this->transactionSetRefId();
	}

	/**
	 * Check user payment
	 *
	 * @return bool
	 *
	 * @throws AsanpardakhtException
	 */
	protected function userPayment()
	{
        $AES = new AES([
            $this->config->get('gateway.asanpardakht.key'),
            $this->config->get('gateway.asanpardakht.iv'),
            $this->config->get('gateway.asanpardakht.username'),
            $this->config->get('gateway.asanpardakht.password')
        ]);
        $string =  $AES->decrypt(Input::get('ReturningParams'));
        $data = explode(',',$string);

		$this->refId = $data[2];
		$this->trackingCode = $data[5];
		$this->cardNumber = $data[7];
		$payRequestResCode = $data[3];

		if ($payRequestResCode == '0' || $payRequestResCode == '00') {
			return true;
		}

		$this->transactionFailed();
		$this->newLog($payRequestResCode, @AsanpardakhtException::$errors[$payRequestResCode]);
		throw new AsanpardakhtException($payRequestResCode);
	}

	/**
	 * Verify user payment from bank server
	 *
	 * @return bool
	 *
	 * @throws AsanpardakhtException
	 * @throws SoapFault
	 */
	protected function verifyPayment()
	{

        $AES = new AES([
            $this->config->get('gateway.asanpardakht.key'),
            $this->config->get('gateway.asanpardakht.iv'),
            $this->config->get('gateway.asanpardakht.username'),
            $this->config->get('gateway.asanpardakht.password')
        ]);
        $string =  $AES->encrypt($this->config->get('gateway.asanpardakht.username').','.$this->config->get('gateway.asanpardakht.password'));


        try {
			$soap = new SoapClient($this->serverUrl);
            $response =  $soap->RequestVerification($this->config->get('gateway.asanpardakht.merchant_id') , $string , $this->trackingCode);
		} catch (\SoapFault $e) {
			$this->transactionFailed();
			$this->newLog('SoapFault', $e->getMessage());
			throw $e;
		}

		if ($response->return != '0') {
			$this->transactionFailed();
			$this->newLog($response->return, AsanpardakhtException::$errors[$response->return]);
			throw new AsanpardakhtException($response->return);
		}

		return true;
	}

	/**
	 * Send settle request
	 *
	 * @return bool
	 *
	 * @throws AsanpardakhtException
	 * @throws SoapFault
	 */
	protected function completePayment()
	{
        $AES = new AES([
            $this->config->get('gateway.asanpardakht.key'),
            $this->config->get('gateway.asanpardakht.iv'),
            $this->config->get('gateway.asanpardakht.username'),
            $this->config->get('gateway.asanpardakht.password')
        ]);
        $string =  $AES->encrypt($this->config->get('gateway.asanpardakht.username').','.$this->config->get('gateway.asanpardakht.password'));



        try {
			$soap = new SoapClient($this->serverUrl);
            $response =  $soap->RequestReconciliation($this->config->get('gateway.asanpardakht.merchant_id') , $string , $this->trackingCode);

        } catch (\SoapFault $e) {
			$this->transactionFailed();
			$this->newLog('SoapFault', $e->getMessage());
			throw $e;
		}

		if ($response->return == '600') {
			$this->transactionSucceed();
			$this->newLog($response->return, Enum::TRANSACTION_SUCCEED_TEXT);
			return true;
		}

		$this->transactionFailed();
		$this->newLog($response->return, AsanpardakhtException::$errors[$response->return]);
		throw new AsanpardakhtException($response->return);
	}
}
