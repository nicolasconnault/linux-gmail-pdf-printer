<?php
// CONFIG
define('UNPROCESSED_FOLDER', 'Orders');
define('PROCESSED_FOLDER', 'old-orders');
define('SUBJECT_PATTERN', '/Fabric Trove - Order ([0-9]{5,6})*/');

require __DIR__ . '/vendor/autoload.php';

if (php_sapi_name() != 'cli') {
    throw new Exception('This application must be run on the command line.');
}

/**
 * Returns an authorized API client.
 * @return Google_Client the authorized client object
 */
function getClient() {
    $client = new Google_Client();
    $client->setApplicationName('Gmail API PHP Quickstart');
    $client->setScopes(array(Google_Service_Gmail::MAIL_GOOGLE_COM, Google_Service_Gmail::GMAIL_READONLY, Google_Service_Gmail::GMAIL_LABELS, Google_Service_Gmail::GMAIL_MODIFY));
    $client->setAuthConfig('credentials.json');
    $client->setAccessType('offline');
    $client->setPrompt('select_account consent');

    // Load previously authorized token from a file, if it exists.
    // The file token.json stores the user's access and refresh tokens, and is
    // created automatically when the authorization flow completes for the first
    // time.
    $tokenPath = 'token.json';
    if (file_exists($tokenPath)) {
        $accessToken = json_decode(file_get_contents($tokenPath), true);
        $client->setAccessToken($accessToken);
    }

    // If there is no previous token or it's expired.
    if ($client->isAccessTokenExpired()) {
        // Refresh the token if possible, else fetch a new one.
        if ($client->getRefreshToken()) {
            $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        } else {
            // Request authorization from the user.
            $authUrl = $client->createAuthUrl();
            printf("Open the following link in your browser:\n%s\n", $authUrl);
            print 'Enter verification code: ';
            $authCode = trim(fgets(STDIN));

            // Exchange authorization code for an access token.
            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
            $client->setAccessToken($accessToken);

            // Check to see if there was an error.
            if (array_key_exists('error', $accessToken)) {
                throw new Exception(join(', ', $accessToken));
            }
        }
        // Save the token to a file.
        if (!file_exists(dirname($tokenPath))) {
            mkdir(dirname($tokenPath), 0700, true);
        }
        file_put_contents($tokenPath, json_encode($client->getAccessToken()));
    }
    return $client;
}


// Get the API client and construct the service object.
$client = getClient();
$service = new Google_Service_Gmail($client);

// Print the labels in the user's account.
$user = 'me';
$results = $service->users_labels->listUsersLabels($user);

$old_orders_label_id = null;

if (count($results->getLabels()) == 0) {
  print "No labels found.\n";
} else {
  foreach ($results->getLabels() as $label) {
    if ($label->getName() == PROCESSED_FOLDER) {
      $old_orders_label_id = $label->getId();
    }
    if ($label->getName() == UNPROCESSED_FOLDER) {
      $new_orders_label_id = $label->getId();
    }
  }
}
$results = $service->users_messages->listUsersMessages($user, array('labelIds' => array($new_orders_label_id)));

if (count($results->getMessages()) == 0) {
  print "No new invoices found.\n";
} else {
  $printed_invoices = array();
  foreach ($results->getMessages() as $message) {
    // Save each invoice on file and send it to print queue
    $email = $service->users_messages->get($user, $message->getId());

    $subject = getHeader($email->getPayload()->getHeaders(), 'Subject');
    if (!preg_match(SUBJECT_PATTERN, $subject, $matches)) {
      break;
    }
    print_invoice($message, $matches[1], $user, $service);

    $printed_invoices[] = $matches[1];

    // Remove "Orders" label and add "old-orders" label
    $mods = new Google_Service_Gmail_ModifyMessageRequest();
    $mods->setAddLabelIds(array($old_orders_label_id));
    $mods->setRemoveLabelIds(array($new_orders_label_id));
    $result = $service->users_messages->modify($user, $message->getId(), $mods);
  }

  if (count($printed_invoices) > 0) {
    echo "Printed invoices on " . date(DATE_RFC2822) . ": ";
    foreach ($printed_invoices as $invoice_id) {
      echo "$invoice_id, ";
    }
    echo "\n";
  }
}


function getHeader($headers, $name) {
  foreach($headers as $header) {
    if($header['name'] == $name) {
      return $header['value'];
    }
  }
}

function print_invoice($email, $invoice_number, $user, $service) {
  $message = $service->users_messages->get($user, $email->getId());
  $payload = $message->getPayload();
  $attachment_id = $payload->parts[1]->body->attachmentId;
  $results = $service->users_messages_attachments->get($user, $email->getId(), $attachment_id);
  $bin = base64_decode(strtr(($results->data), array('-' => '+', '_' => '/')), true);
  if (strpos($bin, '%PDF') !== 0) {
    throw new Exception('Missing the PDF file signature');
  }
  $file_name = "invoice_{$invoice_number}.pdf";
  file_put_contents("invoices/" . $file_name, $bin);
  exec("lpr invoices/$file_name");
}
