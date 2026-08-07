const { Client } = require('ssh2');
const conn = new Client();
const php = `<?php
require __DIR__ . '/../app/bootstrap.php';
global $CONFIG;
$wa = $CONFIG['whatsapp'];
$ver = $wa['api_version'] ?: 'v20.0';
$url = "https://graph.facebook.com/{$ver}/{$wa['business_account_id']}/message_templates?name=your_1_special_offer&fields=name,language,components&limit=3";
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$wa['access_token']], CURLOPT_TIMEOUT=>25]);
$ca = APP_ROOT.'/storage/certs/cacert.pem'; if (is_file($ca)) curl_setopt($ch, CURLOPT_CAINFO, $ca);
$data = json_decode(curl_exec($ch), true); curl_close($ch);
$body = '';
foreach (($data['data'][0]['components'] ?? []) as $c) {
  if (($c['type'] ?? '') === 'BODY') $body = $c['text'] ?? '';
}
$cust = Database::connection()->query('SELECT * FROM customers ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$voucher = VoucherModel::findByCustomerId((int)$cust['id']);
$product = Products::label($cust['selected_product']);
$session = VoucherService::formatSessionLabel($cust['session']);
$date = (new DateTimeImmutable($voucher['event_date']))->format('j F Y');
$store = Settings::get('store_name').', '.Settings::get('branch_name');

$params = [
  $cust['full_name'],
  $voucher['voucher_code'],
  str_replace(['–','—','₹'], ['-','-','Rs.'], $product),
  $date,
  str_replace(['–','—'], ['-','-'], $session),
  $store,
];

$rendered = $body;
for ($i=1;$i<=6;$i++) {
  $rendered = str_replace('{{'.$i.'}}', $params[$i-1], $rendered);
}

echo "body_bytes=".strlen($body)." body_chars=".mb_strlen($body,'UTF-8').PHP_EOL;
echo "rendered_bytes=".strlen($rendered)." rendered_chars=".mb_strlen($rendered,'UTF-8').PHP_EOL;
echo "params=".json_encode($params, JSON_UNESCAPED_UNICODE).PHP_EOL;
foreach ($params as $i=>$p) echo "p".($i+1)."_len=".mb_strlen($p,'UTF-8').PHP_EOL;

// Short param set for retry
$short = [
  mb_substr($cust['full_name'],0,40),
  $voucher['voucher_code'],
  preg_replace('/^Rs\\.?\\s*1\\s*/','', str_replace(['–','—','₹'], ['-','-','Rs.'], $product)),
  (new DateTimeImmutable($voucher['event_date']))->format('d M Y'),
  $cust['session']==='evening' ? '5PM-8PM' : '11AM-2PM',
  'SFS Malleshwaram',
];
$rendered2 = $body;
for ($i=1;$i<=6;$i++) $rendered2 = str_replace('{{'.$i.'}}', $short[$i-1], $rendered2);
echo "short_rendered_chars=".mb_strlen($rendered2,'UTF-8').PHP_EOL;
echo "short_params=".json_encode($short, JSON_UNESCAPED_UNICODE).PHP_EOL;
`;
conn.on('ready', () => {
  conn.sftp((err, sftp) => {
    if (err) throw err;
    const remote = '/home/u432886921/domains/shreeshtafamily.store/public_html/scripts/_len.php';
    const ws = sftp.createWriteStream(remote);
    ws.on('close', () => {
      conn.exec(`php ${remote}; rm -f ${remote}`, (e2, stream) => {
        let o=''; stream.on('data',d=>o+=d); stream.stderr.on('data',d=>o+=d);
        stream.on('close',()=>{ console.log(o); conn.end(); });
      });
    });
    ws.end(php);
  });
}).connect({ host:'217.21.85.101', port:65002, username:'u432886921', password:'Lowda321!', readyTimeout:30000 });
