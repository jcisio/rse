<?php

define('BASE_URL', 'https://rse.axess.fr/index.php');

/**
 * @param $cookie
 * @return mixed|string
 *   String in case of error, decoded object otherwise.
 */
function read_data($cookie) {
  $ch = curl_init();

  curl_setopt($ch, CURLOPT_URL, BASE_URL . '?r=dashboard%2Fdashboard%2Fstream&StreamQuery%5Bfrom%5D=0&StreamQuery%5Blimit%5D=1');
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
  curl_setopt($ch, CURLOPT_ENCODING, 'gzip, deflate');

  $headers = ['Cookie: ' . $cookie];
  curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

  $result = curl_exec($ch);
  if (curl_errno($ch)) {
    return 'Error:' . curl_error($ch);
  }
  curl_close($ch);
  if (!$data = json_decode($result)) {
    return 'Json error';
  }
  return $data;
}

function parse_output($content) {
  $url = BASE_URL . '?r=content%2Fperma&id=' . $content->id;

  $document = new DOMDocument();
  if (!@$document->loadHTML('<?xml encoding="UTF-8">' . $content->output)) {
    return 'Can not parse data.';
  }
  $xpath = new DOMXPath($document);
  $text = trim($xpath->query('//div[@class="media-heading"]')->item(0)->textContent);
  $author = preg_replace('/\s{2,}/', ' → ', $text);
  $text = trim($xpath->query('//div[@class="content"]')->item(0)->textContent);
  $content = preg_replace('/\s{2,}/', ' ', str_replace("\r\n", ' ', $text));
  // Extraction.
  $content = mb_substr($content, 0, mb_strpos($content, ' ', 100)) . '...';

  return "$author : $content $url";
}

function send_irc($params, $messages){
  if (!is_array($messages)) {
    $messages = [$messages];
  }
  $socket = @fsockopen($params["server"], $params["port"]);
  if (!$socket) {
    logging('Can not connect to IRC');
  }
  socket_set_timeout($socket, 30);
  fputs($socket, sprintf("USER %s * * :%s\n", $params["nick"], $params["fullname"]));
  fputs($socket, sprintf("NICK %s\n", $params["nick"]));

  // We need a ping from the server to continue.
  $continuer = 1;
  while ($continuer) {
    $donnees = fgets($socket, 1024);
    $retour = explode(':',$donnees);
    if (rtrim($retour[0]) == 'PING') {
      fputs($socket,'PONG :'.$retour[1]);
      $continuer = 0;
    }
    //if ($donnees) echo $donnees;
  }

  logging('Connected to IRC, joining a channel...', true);
  fputs($socket, sprintf("JOIN %s\n", $params["channel"]));
  foreach ($messages as $message) {
    fputs($socket, sprintf("PRIVMSG %s :%s \n", $params["channel"], $message));
  }
  fputs($socket, sprintf("QUIT\n"));
}

function logging($message, $keep = false) {
  $message = date('Y-m-d H:i:s') . ' ' . $message . "\n";
  $keep ? print($message) : die($message);
}

@include('config.php');
if (getenv('HUMHUB_IDENTITY')) {
  $identity = getenv('HUMHUB_IDENTITY');
}
if (!isset($identity)) {
  logging('Either passer HUMHUB_IDENTITY environment variable or create config.php with `$identity = "...";` to continue. Identity is the cookie of Humhub.');
}
$data = read_data('_identity=' . $identity);
if (is_string($data)) {
  logging($data);
}
$lastContent = $data->content->{$data->contentOrder[0]};
$flag = "/tmp/rse.{$lastContent->guid}";
if (file_exists($flag)) {
  logging('No new message');
}
touch($flag);

$message = parse_output($lastContent);
logging($message, true);

$params = array(
  "server"   => "bart.ows.fr",
  "port"     => 6667,
  "fullname" => "jcisio's bot",
  "nick"     => "jbot",
  "channel"  => "#ows"
);
$messages = [
  "Coucou, il semblerait qu'il y ait un nouvel article sur le RSE.",
  parse_output($lastContent),
];
send_irc($params, $messages);
logging('Message sent to IRC');
