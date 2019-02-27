<?php
/**
 * Created by PhpStorm.
 * User: fengkazi8375
 * Date: 2018/10/26 0026
 * Time: 14:14
 */

class EOS
{
    private $host;
    private $port;
    private $user;
    private $password;

    public function __construct($host, $port, $user, $password)
    {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->password = $password;
    }
    /*
     * 发送请求
     */
    public function send_request($command, $params){
        $ch = curl_init();
        $timeout = 60;
        if(is_array($params)){
            $params = json_encode($params);
        }
        $url = $this->host .":" . $this->port . "/v1" . $command;
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($params))
        );
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        $content = curl_exec($ch);
        $error_no = curl_errno($ch);
        curl_close($ch);
        if($error_no){
            return false;
        }else{
            return json_decode($content, true);
        }
    }

    /*
     * 获取节点信息
     */
    public function get_info(){
        return $this->send_request("/chain/get_info", "");
    }

    /*
     * 获取区块信息
     */
    public function get_block($num = 0, $id = ''){
        if($num > 0){
            $data = array('block_num_or_id' => $num);
        }else{
            $data = array('block_num_or_id' => $id);
        }

        return $this->send_request("/chain/get_block", $data);
    }

    /*
     * 获取账户信息
     */
    public function get_account($account_name){
        $data = array('account_name' => $account_name);
        return $this->send_request("/chain/get_account", $data);
    }

    /*
     * 获取账户余额
     */
    public function get_balance($account_name){
        $data = array('code' => 'eosio.token', 'account' => $account_name, 'symbol' => 'eos');

        $result = $this->send_request("/chain/get_currency_balance", $data);

        if($result){
            $result = explode(" ", $result[0]);
            $balance = $result[0];
        }else{
            $balance = false;
        }

        return $balance;
    }

    /*
     * 获取历史操作
     */
    public function get_actions($account_name, $offset, $size){
        $data = array('account_name' => $account_name, 'pos' => $offset, 'offset' => $size);

        $result = $this->send_request("/history/get_actions", $data);

        $actions = $result['actions'];

        return $actions;
    }


    /*
     * 获取账户信息
     */
    public function get_transaction($txid){
        $data = array('id' => $txid);

        $result = $this->send_request("/history/get_transaction", $data);

        return $result;
    }

    /*
     * 获取需要的keys
     */
    public function get_required_keys(){
        $require_data = "
        {
    \"available_keys\": {$all_keys},
    \"transaction\": {
        \"actions\": [
            {
                \"account\": \"eosio.token\",
                \"authorization\": [
                    {
                        \"actor\": \"{$from}\",
                        \"permission\": \"active\"
                    }
                ],
                \"data\": \"{$binargs_step1}\",
                \"name\": \"transfer\"
            }
        ],
        \"context_free_actions\": [
        ],
        \"context_free_data\": [
        ],
        \"delay_sec\": 0,
        \"expiration\": \"{$expiration}\",
        \"max_kcpu_usage\": 0,
        \"max_net_usage_words\": 0,
        \"ref_block_num\": {$head_block_num},
        \"ref_block_prefix\": {$ref_block_prefix},
        \"signatures\": [
        ]
    }
}";

        $require_result = $this->send_request("/chain/get_required_keys", $require_data);
    }

    /*
     * 发送交易
     */
    public function send_transaction($from, $to, $amount, $memo, $password = ''){
        //首先查询余额
        $balance = $this->get_balance($from);

        //echo "balance {$balance} \n";

        $amount = number_format(floatval($amount), 4);


        if($balance < $amount){
            return array('code' => 0, 'msg' => '余额不足');
        }

        $memo = trim($memo);

        //生成交易信息
        $data = array(
            'code' => "eosio.token",
            'action'=> "transfer",
            'args'=> array(
                'from'=> $from,
                'to'=> $to,
                'quantity' => "{$amount} EOS",
                'memo' => $memo
            )
        );

        $result_step1 = $this->send_request("/chain/abi_json_to_bin", $data);

        if(!$result_step1 || $result_step1['error']){
            return array('code' => 0, 'msg' => '生成交易失败');
        }

        $binargs_step1 = $result_step1['binargs'];

        $wallet_info = $this->get_info();

        $head_block_num = $wallet_info['head_block_num'];

        if(!$head_block_num){
            return array('code' => 0, 'msg' => '钱包链接失败');
        }

        //获取timestmp和ref_block_prefix
        $head_block = $this->get_block($head_block_num);

        $timestmp = $head_block['timestamp'];
        //echo "timestmp {$timestmp} \n";
        $ref_block_prefix = $head_block['ref_block_prefix'];

        $time_format = substr($timestmp, 0, 10) . substr($timestmp, 11, 8);
        $time_after_20_mins = strtotime($time_format) + 1200;
        $expiration = date("Y-m-d", $time_after_20_mins) . "T" . date("H:i:s", $time_after_20_mins) . ".000";

        //echo "expiration {$expiration} \n";

        //实际用到的公钥
        $required_public_key = C('EOS_ACTIVE_PUBLIC_KEY');

        //运行的CHAIN ID
        $chain_id = C('EOS_CHAIN_ID');


        $sign_client = new EOS(C('EOS_SIGN_TRANSACTION_HOST'), C('EOS_SIGN_TRANSACTION_PORT'), "", "");

        //签名，需要由钱包签
        $sign_data = "[{
		\"ref_block_num\": {$head_block_num},
		\"ref_block_prefix\": {$ref_block_prefix},
		\"expiration\": \"{$expiration}\",
		\"actions\": [{
			\"account\": \"eosio.token\",
			\"name\": \"transfer\",
			\"authorization\": [{
				\"actor\": \"{$from}\",
				\"permission\": \"active\"
			}],
			\"data\": \"{$binargs_step1}\"
		}],
		\"signatures\": []
	},
	[\"{$required_public_key}\"], \"{$chain_id}\"
]";

        //echo $sign_data . "\n";

        $result_step2 = $sign_client->send_request("/wallet/sign_transaction", $sign_data);

        //var_dump($result_step2);

        if(!$result_step2 || $result_step2['error']){
            return array('code' => 0, 'msg' => '交易签名失败');
        }

        $signature = $result_step2['signatures'][0];

        //echo "signature {$signature} \n";

        //发布交易
        $push_data = "{
  \"compression\": \"none\",
  \"transaction\": {
    \"expiration\": \"{$expiration}\",
    \"ref_block_num\": {$head_block_num},
    \"ref_block_prefix\": {$ref_block_prefix},
    \"context_free_actions\": [],
    \"actions\": [
        {
            \"account\": \"eosio.token\",
            \"name\": \"transfer\",
            \"authorization\": [
                {
                    \"actor\": \"{$from}\",
                    \"permission\": \"active\"
                }
            ],
            \"data\": \"{$binargs_step1}\"
        }
    ],
    \"transaction_extensions\": []
  },
  \"signatures\": [
        \"{$signature}\"
   ]
}";

        //echo $push_data . "\n";

        $push_result = $this->push_transaction($push_data);

        //var_dump($push_result);

        if(!$push_result || $push_result['error']){
            return array('code' => 0, 'msg' => '交易发布失败');
        }

        $txid = $push_result['transaction_id'];

        return array('code' => 1, 'txhash' => $txid);
    }

    /*
     * 创建账户
     */

    public function create_account($creator, $account_name, $owner_key, $active_key){
        $data = array(
            'code' => 'eosio',
            'action' => 'newaccount',
            'args' => array(
                'creator' => $creator,
                'name' => $account_name,
                'owner' => array(
                    'threshold' => 1,
                    'keys' => array(
                        'key' => $owner_key,
                        'weight' => 1,
                    )
                ),
                'active' => array(
                    'threshold' => 1,
                    'keys' => array(
                        'key' => $active_key,
                        'weight' => 1,
                    )
                ),
            )
        );
        $result =  $this->send_request("/chain/abi_json_to_bin", $data);

        //正常返回 {"bigargs":"xxxxxxxxxxxxx"}

        return $result;
    }

    /*
     * 为账户购买RAM
     */
    public function buy_ram($payer, $receiver, $amount){
        $data = array(
            'code' => 'eosio',
            'action' => 'buyram',
            'args' => array(
                'payer' => $payer,
                'receiver' => $receiver,
                'quant' => "{$amount} EOS",
            )
        );
        $result =  $this->send_request("/chain/abi_json_to_bin", $data);

        //正常返回 {"bigargs":"xxxxxxxxxxxxx"}

        return $result;
    }

    /*
     * 抵押NET、CPU
     */
    public function delegatebw($payer, $receiver, $net_amount, $cpu_amount){
        $data = array(
            'code' => 'eosio',
            'action' => 'buyram',
            'args' => array(
                'from' => $payer,
                'receiver' => $receiver,
                'stake_net_quantity' => "{$net_amount} EOS",
                'stake_cpu_quantity' => "{$cpu_amount} EOS",
                'transfer' => 0 //租借，不是送
            )
        );
        $result =  $this->send_request("/chain/abi_json_to_bin", $data);

        //正常返回 {"bigargs":"xxxxxxxxxxxxx"}

        return $result;
    }

    /*
     * sign_trans
     */
    public function sign_transaction($data){
        $result =  $this->send_request("/wallet/sign_transaction", $data);

        return $result;
    }

    /*
     * 发布交易
     */
    public function push_transaction($data){

        $result =  $this->send_request("/chain/push_transaction", $data);

        return $result;
    }

    /*
     * 创建key
     */
    public function create_key(){
        return $this->send_request("/wallet/create_key", "");
    }

    /*
     * 获取所有public keys(钱包地址)
     */
    public function list_keys(){
        return $this->send_request("/wallet/list_keys", "");
    }


    /*
     * 解锁钱包
     */
    public function unlock($wallet_name, $password){
        $unlock_data = "[\"{$wallet_name}\",\"{$password}\"]";

        $unlock_result = $this->send_request("/wallet/unlock", $unlock_data);

        $unlocked = true;

        if(count($unlock_result) > 0){
            if($unlock_result['error']['what'] != 'Already unlocked'){
                $unlocked = false;
            }
        }

        return $unlocked;
    }

    /*
     * 加锁钱包
     */
    public function lock($wallet_name){
        $data = "\"{$wallet_name}\"";
        $this->send_request("/wallet/lock", $data);
        return true;
    }
}