<?php
/* *****************************************************************************
 *
 * The Frack space-state script, used for both manual and programmatic opening
 * and closing of the space.
 *
 * This is also the Frack space indicator as used on hackerspaces.nl
 * This script is polled by them every 5 minutes (using '/?banner')
 *
 * For use in the Frack wiki (and possibly other places) there is the '/?image'
 * mode of access, which provides *only* the image from the banner.

 * There is also a SpaceAPI interface, which is accessed through '/?api'
 * This returns an JSON object as described on http://spaceapi.net/
 *
 * ****************************************************************************/
require 'json.class.php';
require 'twitteroauth/twitteroauth.php';
require 'config.inc.php';
define('SQLITE_DB', 'sqlite:checkin.sqlite');
define('STATUS_FILE', 'status.txt');
define('STATUS_PAGE', 'status_page.html');
define('SPACE_STATUS', (bool) trim(file_get_contents(STATUS_FILE)));

class Template {
  function __construct($template) {
    $this->template = $template;
  }

  private function replaceCallback($m) {
    return isset($this->replacements[$m[1]]) ? $this->replacements[$m[1]] : '';
  }

  function apply($replacements) {
    $this->replacements = $replacements;
    $result = preg_replace_callback(
        '/\{(\w+)\}/', array(&$this,'replaceCallback') ,$this->template);
    unset($this->replacements);
    return $result;
  }
}

function RecentCheckins() {
  $db = new PDO(SQLITE_DB);
  $query = 'SELECT * FROM `event` ORDER BY `ID` DESC LIMIT 10';
  $checkins = array();
  foreach ($db->query($query) as $checkin) {
    $checkins[] = array('name' => 'Frack',
                        'type' => $checkin['action'],
                        'timestamp' => (int) $checkin['timestamp']);
  }
  return $checkins;
}


function RecordCheckin($newstate) {
  $db = new PDO(SQLITE_DB);
  $query = $db->prepare('INSERT INTO `event` (`action`, `timestamp`)
                         VALUES (:action, :timestamp)');
  $query->execute(array(':action' => $newstate ? 'check-in' : 'check-out',
                        ':timestamp' => time()));
}


function SetSpaceStatus($status, $tweet=true) {
  $fp = fopen(STATUS_FILE, 'w');
  fwrite($fp, $status ? '1' : '0');
  fclose($fp);
  RecordCheckin($status);
  if ($tweet) {
    TweetSpaceState($status);
  }
  return $status;
}


function TweetSpaceState($status) {
  try {
    $twitter_api = new TwitterOAuth(
        OAUTH_CONSUMERKEY, OAUTH_CONSUMERSECRET,
        OAUTH_ACCESSTOKEN, OAUTH_ACCESSTOKENSECRET);
    $parameters = array('status' => sprintf(
        'The space is now %s (changed on %s)',
        $status ? 'open!' :'closed.',
        date('Y-n-j H:i')));
    $twitter_api->post('statuses/update', $parameters);
  } catch (Exception $e) {
    echo 'Caught exception: ',  $e->getMessage(), "\n", '<br><br>';
    echo 'Twitter API barked at us, likely because of rate limiting;';
    echo 'Please try again after a minute.<br>';
    echo 'If the problem persists, please contact info[at]frack[dot]nl';
    exit();
  }
}


if (isset($_GET['api'])) {
  header('Access-Control-Allow-Origin: *');
  header('Cache-Control: no-cache');
  header('Content-Type: application/json');
  $events = RecentCheckins();
  echo PrettyJson(array(
      'api' => '0.13',
      'space' => 'Frack',
      'logo' => 'http://frack.nl/w/Frack-logo.png',
      'url' => 'http://frack.nl',
      'location' => array(
          'address' => 'Zuiderplein 33, 8911 AN, Leeuwarden, The Netherlands',
          'lat' => 53.197916,
          'lon' => 5.796962),
      'spacefed' => array(
          'spacenet' => true,
          'spacesaml' => false,
          'spacephone' => false),
      'state' => array(
          'open' => SPACE_STATUS,
          'lastchange' => $events[0]['timestamp'],
          'message' => SPACE_STATUS ? 'Je bent welkom' : 'Sorry, we zijn gesloten',
          'icon' => array(
              'closed' => 'http://frack.nl/spacestate/icon_closed.png',
              'open' => 'http://frack.nl/spacestate/icon_open.png')),
      'events' => $events,
      'contact' => array(
          'phone' => '+31681563934',
          'keymaster' => array(
              array(
                  'name' => 'Keyholders mailing list',
                  'email' => 'key-holders@frack.nl')),
          'irc' => 'irc://irc.eth-0.nl/#frack',
          'twitter' => '@fracknl',
          'email' => 'info@frack.nl',
          'ml' => 'general@frack.nl',
          'issue_mail' => 'elmer.delooff@gmail.com'),
      'issue_report_channels' => array('issue_mail'),
      'feeds' => array(
          'wiki' => array(
              'type' => 'atom',
              'url' => 'http://frack.nl/wiki/Special:RecentChanges?feed=atom'),
          'calendar' => array(
              'type' => 'ical',
              'url' => 'https://www.google.com/calendar/ical/7b7vbccb6rcfuj10n1jfic30bc%40group.calendar.google.com/public/basic.ics')),
      'cache' => array('schedule' => 'm.02'),
      'projects' => array(
          'http://frack.nl/wiki/Projecten',
          'https://github.com/frack')));
} elseif (isset($_GET['banner'])) {
  printf('<html>
   <head>
     <title>Frack space indicator</title>
     <style type="text/css">* {margin:0;padding:0}</style>
   </head><body><a href="http://frack.nl"><img src="banner_%s.png" /></a></body></html>',
   SPACE_STATUS ? 'open' : 'closed');
} elseif (isset($_GET['image'])) {
  header(sprintf('Location: /spacestate/banner_%s.png',
                 SPACE_STATUS ? 'open' : 'closed'));
} else {
  // regular door script here
  $status = SPACE_STATUS;
  $update_error = '';
  if (isset($_POST['pass'])) {
    if ($_POST['pass'] != POST_SECRET) {
      $update_error = '<p class="error">Sorry, the password you entered is not correct.</p>';
    } elseif (!isset($_POST['newstate'])) {
      $update_error = '<p class="error">The new space state was not specified :(</p>';
    } elseif ((bool) $_POST['newstate'] != SPACE_STATUS) {
      // Only update the status when it's different from the current.
      $status = SetSpaceStatus((bool) $_POST['newstate']);
    }
  }

  $template = new Template(file_get_contents(STATUS_PAGE));
  if ($status) {
    $replacements = array(
        'title' => 'The Frack hackerspace is now open!',
        'body_class' => 'open',
        'message_error' => $update_error,
        'message_state' => 'The space is now open.<br>You are welcome to come over!',
        'message_form' => 'Please close the space if you are the last person to leave.',
        'next_state' => '0',
        'button_text' => 'Close the space');
  } else {
    $replacements = array(
        'title' => 'The Frack hackerspace is now closed :(',
        'body_class' => 'closed',
        'message_error' => $update_error,
        'message_state' => 'Sorry, but we\'re closed right now, try again later.',
        'message_form' => 'Space opened but state says "no"? Open the space!',
        'next_state' => '1',
        'button_text' => 'Open the space');
  }
  echo $template->apply($replacements);
}
?>
