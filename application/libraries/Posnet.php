<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

class Posnet
{
    public function __construct()
    {
    }

    public function oosRequest($bank)
    {
        $new_order_id = round(111, 999) . $bank['order_id'];
        $xid = substr("00000000000000000000" . $new_order_id, -20);
        $expDate = $bank['cc_expire_date_year'] . $bank['cc_expire_date_month'];
        $amount = (int)($bank['total'] * 100);
        if ($bank['instalment'] != 0) {
            $instalment = $bank['instalment'];
        } else {
            $instalment = "00";
        }
        $xml = "<?xml version=\"1.0\" encoding=\"ISO-8859-9\"?>" .
            "<posnetRequest>" .
            "<mid>" . $bank['posnet_merchant_id'] . "</mid>" .
            "<tid>" . $bank['posnet_terminal_id'] . "</tid>" .
            "<oosRequestData>" .
            "<posnetid>" . $bank['posnet_id'] . "</posnetid>" .
            "<ccno>" . $bank['cc_number'] . "</ccno>" .
            "<expDate>" . $expDate . "</expDate>" .
            "<cvc>" . $bank['cc_cvv2'] . "</cvc>" .
            "<amount>" . $amount . "</amount>" .
            "<currencyCode>YT</currencyCode>" .
            "<installment>" . $instalment . "</installment>" .
            "<XID>" . $xid . "</XID>" .
            "<cardHolderName>" . $bank['cc_owner'] . "</cardHolderName>" .
            "<tranType>Sale</tranType>" .
            "</oosRequestData>" .
            "</posnetRequest>";
        $url = $bank['posnet_classic_url'];
        $result = $this->curlSend($url, $xml);
        return $result;
    }

    public function oosResolve($mid, $tid, $bankData, $merchantData, $sign, $url)
    {
        $xml = "<?xml version=\"1.0\" encoding=\"ISO-8859-9\"?>" .
            "<posnetRequest>" .
            "<mid>" . $mid . "</mid>" .
            "<tid>" . $tid . "</tid>" .
            "<oosResolveMerchantData>" .
            "<bankData>" . $bankData . "</bankData>" .
            "<merchantData>" . $merchantData . "</merchantData>" .
            "<sign>" . $sign . "</sign>" .
            "</oosResolveMerchantData>" .
            "</posnetRequest>";
        $result = $this->curlSend($url, $xml);
        return $result;
    }

    public function oosTran($mid, $tid, $bankData, $url)
    {
        $xml = "<?xml version=\"1.0\" encoding=\"ISO-8859-9\"?>" .
            "<posnetRequest>" .
            "<mid>" . $mid . "</mid>" .
            "<tid>" . $tid . "</tid>" .
            "<oosTranData>" .
            "<bankData>" . $bankData . "</bankData>" .
            "<wpAmount>0</wpAmount>" .
            "</oosTranData>" .
            "</posnetRequest>";
        $result = $this->curlSend($url, $xml);
        return $result;
    }

    public function createForm($bank)
    {
        $posnetRequest = $this->oosRequest($bank);
        $xml = simplexml_load_string($posnetRequest);
        $approved = isset($xml->approved) ? (string)$xml->approved : '';
        $form = null;
        if ($approved != 1) {
            $form = array('error' => 'Posnet Ön Onay Hatası: ' . (string)$xml->respText);
        } else if ($approved == 1) {
            $data1 = (string)$xml->oosRequestDataResponse->data1;
            $data2 = (string)$xml->oosRequestDataResponse->data2;
            $sign = (string)$xml->oosRequestDataResponse->sign;
            $inputs = array();
            $inputs = array(
                'posnetData' => $data1,
                'posnetData2' => $data2,
                'mid' => $bank['posnet_merchant_id'],
                'posnetID' => $bank['posnet_id'],
                'digest' => $sign,
                'vftCode' => "K001",
                'merchantReturnURL' => $bank['success_url'],
                'lang' => "tr",
                'url' => "",
                'openANewWindow' => "0",
                'useJokerVadaa' => "1"
            );
            $action = $bank['posnet_3D_url'];
            $form = '<form id="webpos_form" name="webpos_form" method="post" action="' . $action . '">';
            foreach ($inputs as $key => $value) {
                $form .= '<input type="hidden" name="' . $key . '" value="' . $value . '" />';
            }
            $form .= '</form>';
        }
        return $form;
    }

    public function methodResponse($bank)
    {
        $response = array();
        $response['form'] = $this->createForm($bank);
        return $response;
    }

    public function bankResponse($bank_response, $bank)
    {
        $response = array();
        $response['message'] = '';
        $merchantData = isset($bank_response['MerchantPacket']) ? $bank_response['MerchantPacket'] : "";
        $bankData = isset($bank_response['BankPacket']) ? $bank_response['BankPacket'] : "";
        $sign = isset($bank_response['Sign']) ? $bank_response['Sign'] : "";
        $url = $bank['posnet_classic_url'];
        $oosResponse = $this->oosResolve($bank['posnet_merchant_id'], $bank['posnet_terminal_id'], $bankData, $merchantData, $sign, $url);
        $xml = simplexml_load_string($oosResponse);
        $approved = (string)$xml->approved;
        $mdStatus = (string)$xml->oosResolveMerchantDataResponse->mdStatus;
        if ($approved == 1) {
            $oosTran = $this->oosTran($bank['posnet_merchant_id'], $bank['posnet_terminal_id'], $bankData, $url);
            $xmlTran = simplexml_load_string($oosTran);
            $approvedTran = (string)$xmlTran->approved;
            if ($approvedTran == 1) {
                $hostlogkey = (string)$xmlTran->hostlogkey;
                $authCode = (string)$xmlTran->authCode;
                $inst1 = (string)$xmlTran->instInfo->inst1;
                $amnt1 = (string)$xmlTran->instInfo->amnt1;
                $response['result'] = 1;
                $response['message'] .= 'Ödeme Başarılı<br/>';
                $response['message'] .= 'AuthCode : ' . $authCode . '<br/>';
                $response['message'] .= 'HostLogKey : ' . $hostlogkey . '<br/>';
                $response['message'] .= 'Instalment : ' . $inst1 . '<br/>';
                $response['message'] .= 'Amount : ' . $amnt1 . '<br/>';
            } else {
                $response['result'] = 0;
                $response['message'] .= ((string)$xmlTran->respText) . ' TranError Code:' . ((string)$xmlTran->respCode);
            }
        } else {
            $response['result'] = 0;
            $response['message'] .= ((string)$xml->respText) . ' Error Code:' . ((string)$xml->respCode);
        }
        return $response;
    }

    public function curlSend($url, $xml)
    {
        $posnet_static_ip = '';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        if ($posnet_static_ip != '') {
            curl_setopt($ch, CURLOPT_INTERFACE, $posnet_static_ip);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'xmldata=' . (urlencode($xml)));
        $result = curl_exec($ch);
        if (curl_errno($ch)) {
            $result = '<posnetResponse>
			<approved>0</approved>
			<respCode>cUrlError</respCode>
			<respText>cUrl Error: ' . curl_error($ch) . '</respText>
			</posnetResponse>';
        }
        curl_close($ch);
        return $result;
    }
}
