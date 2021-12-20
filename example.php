<?php
include "./src/WxworkFinanceSdk.php";
include "./src/WxworkFinanceSdkException.php";

$wx = new WxworkFinanceSdk("your corpid", "your secret");
$privateKey = file_get_contents('/path/to/your/private_key.pem');

$arr = json_decode($wx->getChatData(), true);

foreach($arr['chatdata'] as $item) {
    $decryptRandKey = null;
    openssl_private_decrypt(base64_decode($item['encrypt_random_key']), $decryptRandKey, $privateKey, OPENSSL_PKCS1_PADDING);

    $str = $wx->decryptData($decryptRandKey, $item['encrypt_chat_msg']);
    $msg = json_decode($str, true);

    $msgTime = date("Y-m-d H:i:s", $msg['msgtime']/1000);
    $cont = match($msg['msgtype']) {
        'text'  => $msg['text']['content'],
        'image' => tempnam(__DIR__.'/tmp', "IMAGE_"),
        default => $msg['msgtype'],
    };
    if($msg['msgtype'] == 'image') {
        $wx->downloadMedia($msg['image']['sdkfileid'], $cont);
    }
    echo $msgTime . "\t" . $cont . PHP_EOL;
}