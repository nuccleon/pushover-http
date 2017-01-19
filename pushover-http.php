<?php
require_once 'vendor/autoload.php';
require_once 'DebugLogger.php';
require_once 'PhpErrorHandler.php';

date_default_timezone_set('UTC');

use LeonardoTeixeira\Pushover\Client;
use LeonardoTeixeira\Pushover\Message;
use LeonardoTeixeira\Pushover\Priority;
use LeonardoTeixeira\Pushover\Sound;
use LeonardoTeixeira\Pushover\Exceptions\PushoverException;

const CONFIG_FILE = 'pushover-http-config.php';
const LOG_FILE = '/var/tmp/pushover-http.log';
const VERBOSITY = DebugLogger::DEBUG;

$logger = new DebugLogger(VERBOSITY, LOG_FILE);
$errorhanlder = new PhpErrorHandler(VERBOSITY, LOG_FILE);
$logger->logDebug("Process '".($_SERVER['REQUEST_URI'])."'");

include CONFIG_FILE; // optional include of the configuration after PhpErrorHandler instantiaation

/**
 * HTTP API DESCRIPTION
 *
 * Pass parameter either with GET or with POST
 * At least user, token and message
 */
$dateFormat = 'Y-n-d H:i:s';   // supported format, e.g. 2009-02-15 15:16:17
$mandatory = [ true, 'value' => null ]; // Comes either form POST/GET or from pushover-http.config.php. Do not change from null to ""!
$optional = [ false, 'value' => null ]; // null variables will not be set in the message object (see pushover message preparation section)
$params = [
   'user'      =>  $mandatory ,// the user/group key (not e-mail address) of your user (or you), viewable
                               // when logged into our dashboard (often referred to as USER_KEY in our
                               // documentation and code examples)
   'token'     =>  $mandatory, // your application's API token
   'message'   =>  $mandatory, // your message
   'priority'  =>  $optional,  // send as -2 to generate no notification/alert, -1 to always send as a quiet
                               // notification, 1 to display as high-priority and bypass the user's quiet hours,
                               // or 2 to also require confirmation from the user
   'device'    =>  $optional,  // your user's device name to send the message directly to that device,
                               // rather than all of the user's devices (multiple devices may be separated by a comma)
   'title'     =>  $optional,  // your message's title, otherwise your app's name is used
   'url'       =>  $optional,  // a supplementary URL to show with your message
   'urlTitle'  =>  $optional,  // a title for your supplementary URL, otherwise just the URL is shown
   'sound'     =>  $optional,  // The name of one of the sounds supported by device clients to override the user's default sound choice
   'html'      =>  $optional,  // tbd
   'date'      =>  $optional,  // a Unix timestamp of your message's date and time to display to the user, rather than the time your message is received by our API
                               // has to be formated as defined in $dateFormat
   'echo'      =>  $optional   // set this to redirect debug logs to the http response
];

try {
   /***************************************************************************
    * Select requested method...
    */
   switch($_SERVER['REQUEST_METHOD']) {
      case 'GET':
         $request = &$_GET; break;
      case 'POST':
         $request = &$_POST; break;
      default:
         throw new Exception("Unsupported request method '".$_SERVER['REQUEST_METHOD']."'");
   }
   /***************************************************************************
    * Sanity check request query. Don't accept unsupported API keys.
    */
   foreach($request as $key => $value) {
      if(!array_key_exists($key, $params))
         throw new Exception("Unsupported parameter '".$key."'");
   }
   /***************************************************************************
    * Preset param with configuration values got from pushover-http.config.php
    * They will be overwritten with POST/GET values.
    */
   if(!file_exists(CONFIG_FILE)) {
      $logger->logDebug("Dit not found any configuration");
   } else if(!isset($param_config) || !is_array($param_config)) {
      $logger->logWarn("Config file does not contain any valid configuration.");
   } else {
      foreach($param_config as $key => $value) {
         if(!isset($params[$key])) { // check if parameter from config file exists in the API params ...
            $logger->logError("Invalid parameter '".$key."' in config file");
         } else if($value != "") {  // and copy it to the API params if it has also a valid value
            $params[$key]['value'] = $value;
         }
      }
   }
   /***************************************************************************
    * Check if all mandatory parameters are passed to the script.
    * copy parameters from the request query to the internal data
    * represenation ($params) that will be used for the push message.
    */
    foreach($params as $key => &$value) {
      $mandatory = $value[0];
      $isset = isset($request[$key]) && !empty($request[$key]);
      $configured = !is_null($value['value']);
      if(!$isset && !$configured) {
         if($mandatory) {
            // throw if the mandatory API key is not part of the request and was not set with the configuration
            $reason = isset($request[$key]) ? 'empty' : 'missing';
            throw new Exception("Mandatory parameter '".$key."' is ".$reason);
         }
      } else {
         $method = CONFIG_FILE;
         $requirement = $mandatory ? 'mandatory' : 'optional';
         if($isset) {
            $value['value'] = $request[$key];
            $method = $_SERVER['REQUEST_METHOD'];
         }
         $logger->logDebug("Got ".$requirement." '".$key."=".$value['value']."' via '".$method."'");

         // special handling for date required... need DateTime object
         if($key == 'date') {
            $value['value'] = DateTime::createFromFormat($dateFormat, $request[$key]);
            $errors = DateTime::getLastErrors();
            if($errors['error_count'] != 0)
               $error = reset($errors['errors']);
            else if($errors['warning_count'] != 0)
               $error = reset($errors['warnings']);
            if(isset($error))
               throw new Exception("Optional parameter '".$key."' has to be '".$dateFormat."' formatted (".$error.")");
         }
      }
   }
} catch (Exception $e) {
   $logger->logFatal($e);
   // return error text to the requestor and return HTTP 'Bad Request'
   echo($e->getMessage());
   http_response_code(400);
   exit;
}
try {
   /***************************************************************************
    * Pushover message preparation
    */
   $message = new Message();
   // values are preinitialized with null
   if(!is_null($params['message']['value']))
      $message->setMessage($params['message']['value']);
   if(!is_null($params['title']['value']))
      $message->setTitle($params['title']['value']);
   if(!is_null($params['url']['value']))
      $message->setUrl($params['url']['value']);
   if(!is_null($params['urlTitle']['value']))
      $message->setUrlTitle($params['urlTitle']['value']);
   if(!is_null($params['priority']['value']))
      $message->setPriority($params['priority']['value']);
   if(!is_null($params['sound']['value']))
      $message->setSound($params['sound']['value']);
   if(!is_null($params['html']['value'] != ""))
      $message->setHtml($params['html']['value']);
   if(!is_null($params['date']['value']))
      $message->setDate($params['date']['value']);
} catch (Exception $e) {
   $logger->logFatal($e);
   // return error text to the requestor and return HTTP 'Bad Request'
   echo($e->getMessage());
   http_response_code(400);
   exit;
}
try {
   /***************************************************************************
    * Pushover message sending
    */
   $client = new Client($params['user']['value'], $params['token']['value']);
   $client->push($message, $params['device']['value']);

   $logger->logDebug("The message has been pushed!");
} catch (Exception $e) {
   $logger->logFatal($e);
   // return error text to the requestor and return HTTP 'Internal Server Error'
   echo($e->getMessage());
   http_response_code(500);
   exit;
}

?>
