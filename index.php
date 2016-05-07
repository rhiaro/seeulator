<?
session_start();
date_default_timezone_set(file_get_contents("http://rhiaro.co.uk/tz"));
if(isset($_GET['logout'])){ session_unset(); session_destroy(); header("Location: /seeulator"); }
if(isset($_GET['reset']) && $_GET['reset'] == "feed") { unset($_SESSION['feed']); unset($_SESSION['feed_source']); header("Location: /seeulator"); }

include "link-rel-parser.php";

$base = "https://apps.rhiaro.co.uk/seeulator";
if(isset($_GET['code'])){
  $auth = auth($_GET['code'], $_GET['state']);
  if($auth !== true){ $errors = $auth; }
  else{
    $response = get_access_token($_GET['code'], $_GET['state']);
    if($response !== true){ $errors = $auth; }
    else {
      header("Location: ".$_GET['state']);
    }
  }
}

// VIP cache
$vips = array("http://rhiaro.co.uk", "http://rhiaro.co.uk/", "http://tigo.rhiaro.co.uk/");

function auth($code, $state, $client_id="https://apps.rhiaro.co.uk/seeulator"){
  
  $params = "code=".$code."&redirect_uri=".urlencode($state)."&state=".urlencode($state)."&client_id=".$client_id;
  $ch = curl_init("https://indieauth.com/auth");
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/x-www-form-urlencoded", "Accept: application/json"));
  curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
  //curl_setopt($ch, CURLOPT_HEADERFUNCTION, "dump_headers");
  $response = curl_exec($ch);
  $response = json_decode($response, true);
  $_SESSION['me'] = $response['me'];
  $info = curl_getinfo($ch);
  curl_close($ch);
  
  if(isset($response) && ($response === false || $info['http_code'] != 200)){
    $errors["Login error"] = $info['http_code'];
    if(curl_error($ch)){
      $errors["curl error"] = curl_error($ch);
    }
    return $errors;
  }else{
    return true;
  }
}

function get_access_token($code, $state, $client_id="https://apps.rhiaro.co.uk/seeulator"){
  
  $params = "me={$_SESSION['me']}&code=$code&redirect_uri=".urlencode($state)."&state=".urlencode($state)."&client_id=$client_id";
  $token_ep = discover_endpoint($_SESSION['me'], "token_endpoint");
  $ch = curl_init($token_ep);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/x-www-form-urlencoded"));
  curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
  $response = Array();
  parse_str(curl_exec($ch), $response);
  $info = curl_getinfo($ch);
  curl_close($ch);
  
  if(isset($response) && ($response === false || $info['http_code'] != 200)){
    $errors["Login error"] = $info['http_code'];
    if(curl_error($ch)){
      $errors["curl error"] = curl_error($ch);
    }
    return $errors;
  }else{
    $_SESSION['access_token'] = $response['access_token'];
    return true;
  }
  
}

function discover_endpoint($url, $rel="micropub"){
  if(isset($_SESSION[$rel])){
    return $_SESSION[$rel];
  }else{
    $res = head_http_rels($url);
    $rels = $res['rels'];
    if(!isset($rels[$rel][0])){
      $parsed = json_decode(file_get_contents("https://pin13.net/mf2/?url=".$url), true);
      if(isset($parsed['rels'])){ $rels = $parsed['rels']; }
    }
    if(!isset($rels[$rel][0])){
      // TODO: Try in body
      return "Not found";
    }
    $_SESSION[$rel] = $rels[$rel][0];
    return $rels[$rel][0];
  }
}

function context(){
  return array(
      "@context" => array("as" => "http://www.w3.org/ns/activitystreams#", "blog" => "http://vocab.amy.so/blog#")
    );
}

function form_to_json($post){

  $context = context();
  $data = array_merge($context, $post);
  unset($data['create']);

  foreach($data as $k => $v){
    if((is_string($v) && strlen($v) < 1) || (is_array($v) && count($v) < 1)){
      unset($data[$k]);
    }
  }
  
  // Datetimes
  $data['as:published'] = $post['year']."-".$post['month']."-".$post['day']."T".$post['time'].$post['zone'];
  unset($data['year']); unset($data['month']); unset($data['day']); unset($data['time']); unset($data['zone']);
  $data['as:startTime'] = $post['startyear']."-".$post['startmonth']."-".$post['startday']."T".$post['starttime'].$post['startzone'];
  unset($data['startyear']); unset($data['startmonth']); unset($data['startday']); unset($data['starttime']); unset($data['startzone']);
  $data['as:endTime'] = $post['endyear']."-".$post['endmonth']."-".$post['endday']."T".$post['endtime'].$post['endzone'];
  unset($data['endyear']); unset($data['endmonth']); unset($data['endday']); unset($data['endtime']); unset($data['endzone']);

  // Types
  if(isset($data["as:origin"]) && isset($data["as:target"]) && isset($data["as:startTime"]) && isset($data["as:endTime"])){
    var_dump($data["as:origin"]);
    $data["@type"] = array("as:Travel");
  }elseif(isset($data["as:startTime"]) && isset($data["as:endTime"]) && isset($data["as:location"])){
    if(isset($data["as:inReplyTo"])){
      $data["@type"] = array("as:Accept");
    }else{
      $data["@type"] = array("as:Event");
    }
  }
  
  // URIs
  $uris = array("as:image", "as:target", "as:origin", "as:location");
  foreach($uris as $uri){
    unset($data[$uri]);
    if(isset($post[$uri]) && strlen($post[$uri]) > 0){
      $data[$uri] = array("@id" => $post[$uri]);
    }
  }
  $multiuris = array("as:inReplyTo");
  foreach($multiuris as $muri){
    if(isset($post[$muri])){
      unset($data[$muri]);
      if(is_array($post[$muri]) && count($post[$muri]) > 0) {
        $muris = $post[$muri];
      }elseif(is_string($post[$muri]) && strlen($post[$muri]) > 0){
        $muris = explode(",", $post[$muri]);
        $muris = array_map('trim', $muris);
      }
      foreach($muris as $u){
        $data[$muri][] = array("@id" => $u);
      }
    }
  }
  
  // Tags
  if(isset($post['moretags'])){
    if(!isset($post['as:tag'])) $post['as:tag'] = array();
    $values = explode(",", $post['moretags']);
		$values = array_map('trim', $values);
		$data['as:tag'] = array_merge($post['as:tag'], $values);
		unset($data['moretags']);
  }
  
  if(!in_array("rsvp", $data['as:tag'])){
    unset($data['blog:rsvp']);
  }
  
  $json = stripslashes(json_encode($data, JSON_PRETTY_PRINT));
  return $json;
}

function post_to_endpoint($json, $endpoint){
  $ch = curl_init($endpoint);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/activity+json"));
  curl_setopt($ch, CURLOPT_HTTPHEADER, array("Authorization: Bearer ".$_SESSION['access_token']));
  curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
  $response = Array();
  parse_str(curl_exec($ch), $response);
  $info = curl_getinfo($ch);
  curl_close($ch);
  
  return $response;
}

if(isset($_POST['create'])){
  if(isset($_SESSION['me'])){
    $endpoint = discover_endpoint($_SESSION['me']);
    $result = post_to_endpoint(form_to_json($_POST), $endpoint);
  }else{
    $errors["Not signed in"] = "You need to sign in to post.";
  }
}

?>
<!doctype html>
<html>
  <head>
    <title>seeulator</title>
    <link rel="stylesheet" type="text/css" href="https://apps.rhiaro.co.uk/css/normalize.min.css" />
    <link rel="stylesheet" type="text/css" href="https://apps.rhiaro.co.uk/css/main.css" />
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style type="text/css">
      pre { max-height: 8em; overflow: auto; }
      #time, #zone, #starttime, #startzone, #endtime, #endzone { max-width: 6em; }
    </style>
  </head>
  <body>
    <main class="w1of2 center">
      <h1>Seeulator</h1>
      
      <?if(isset($errors)):?>
        <div class="fail">
          <?foreach($errors as $key=>$error):?>
            <p><strong><?=$key?>: </strong><?=$error?></p>
          <?endforeach?>
        </div>
      <?endif?>
      
      <?if(isset($result)):?>
        <div>
          <p>The response from you your creation endpoint:</p>
          <code><?=$endpoint?></code>
          <pre>
            <? var_dump($result); ?>
          </pre>
        </div>
      <?endif?>
      
      <form method="post" role="form" id="consume">
        <p><input type="submit" value="Post" class="neat" name="create" /></p>
        <p><label for="name" class="neat">Name</label> <input type="text" name="as:name" id="name" class="neat" /></p>
        <p><label for="content" class="neat">Description</label> <input type="text" name="as:content" id="content" class="neat" /></p>
        <p><label for="rsvpto" class="neat">RSVP to</label> <input type="text" name="as:inReplyTo" id="rsvpto" class="neat" /></p>
        <p>
          <input type="radio" name="blog:rsvp" value="yes" checked="checked" id="rsvpyes" /> <label for="rsvpyes">Yes</label>
          <input type="radio" name="blog:rsvp" value="maybe" id="rsvpmaybe" /> <label for="rsvpmaybe">Maybe</label>
          <input type="radio" name="blog:rsvp" value="no" id="rsvpno" /> <label for="rsvpno">No</label>
        </p>
        <p><label for="location" class="neat">Location</label> <input type="text" name="as:location" id="location" class="neat"  value="http://dbpedia.org/resource/"/></p>
        <p><label for="startLocation" class="neat">Start Location</label> <input type="text" name="as:origin" id="startLocation" class="neat" value="http://dbpedia.org/resource/"/></p>
        <p><label for="endLocation" class="neat">End Location</label> <input type="text" name="as:target" id="endLocation" class="neat"  value="http://dbpedia.org/resource/"/></p>
        <p><label>Start: </label>
          <select name="startyear" id="startyear">
            <option value="2016" selected>2016</option>
            <option value="2016">2015</option>
          </select>
          <select name="startmonth" id="startmonth">
            <?for($i=1;$i<=12;$i++):?>
              <option value="<?=date("m", strtotime("2016-$i-01"))?>"<?=(date("n") == $i) ? " selected" : ""?>><?=date("M", strtotime("2016-$i-01"))?></option>
            <?endfor?>
          </select>
          <select name="startday" id="startday">
            <?for($i=1;$i<=31;$i++):?>
              <option value="<?=date("d", strtotime("2016-01-$i"))?>"<?=(date("j") == $i) ? " selected" : ""?>><?=date("d", strtotime("2016-01-$i"))?></option>
            <?endfor?>
          </select>
          <input type="text" name="starttime" id="starttime" value="<?=date("H:i:s")?>" />
          <input type="text" name="startzone" id="startzone" value="<?=date("P")?>" />
        </p>
        <p><label>End: </label>
          <select name="endyear" id="endyear">
            <option value="2016" selected>2016</option>
            <option value="2016">2015</option>
          </select>
          <select name="endmonth" id="endmonth">
            <?for($i=1;$i<=12;$i++):?>
              <option value="<?=date("m", strtotime("2016-$i-01"))?>"<?=(date("n") == $i) ? " selected" : ""?>><?=date("M", strtotime("2016-$i-01"))?></option>
            <?endfor?>
          </select>
          <select name="endday" id="endday">
            <?for($i=1;$i<=31;$i++):?>
              <option value="<?=date("d", strtotime("2016-01-$i"))?>"<?=(date("j") == $i) ? " selected" : ""?>><?=date("d", strtotime("2016-01-$i"))?></option>
            <?endfor?>
          </select>
          <input type="text" name="endtime" id="endtime" value="<?=date("H:i:s")?>" />
          <input type="text" name="endzone" id="endzone" value="<?=date("P")?>" />
        </p>
        <p><label for="cost" class="neat">Cost</label> <input type="text" name="blog:cost" id="cost" class="neat" /></p>
        <p><label for="tags" class="neat">Tags</label> <input type="text" name="moretags" id="tags" class="neat" /></p>
        <p>
          <input type="checkbox" name="as:tag[]" value="rsvp" id="tagrsvp" /> <label for="tagrsvp">rsvp</label>
          <input type="checkbox" name="as:tag[]" value="event" id="tagevent" /> <label for="tagevent">event</label>
          <input type="checkbox" name="as:tag[]" value="attendee" id="tagattendee" /> <label for="tagattendee">attendee</label>
          <input type="checkbox" name="as:tag[]" value="organiser" id="tagorganiser" /> <label for="tagorganiser">organiser</label>
          <input type="checkbox" name="as:tag[]" value="speaker" id="tagspeaker" /> <label for="tagspeaker">speaker</label>
        </p>
        <p>
          <input type="checkbox" name="as:tag[]" value="travel" id="tagtravel" /> <label for="tagtravel">travel</label>
          <input type="checkbox" name="as:tag[]" value="journey" id="tagjourney" /> <label for="tagjourney">journey</label>
        </p>
        <p>
          <input type="checkbox" name="as:tag[]" value="plane" id="tagplane" /> <label for="tagplane">plane</label>
          <input type="checkbox" name="as:tag[]" value="flight" id="tagflight" /> <label for="tagflight">flight</label>
          <input type="checkbox" name="as:tag[]" value="train" id="tagtrain" /> <label for="tagtrain">train</label>
          <input type="checkbox" name="as:tag[]" value="coach" id="tagcoach" /> <label for="tagcoach">coach</label>
          <input type="checkbox" name="as:tag[]" value="bus" id="tagbus" /> <label for="tagbus">bus</label>
          <input type="checkbox" name="as:tag[]" value="car" id="tagcar" /> <label for="tagcar">car</label>
          <input type="checkbox" name="as:tag[]" value="roadtrip" id="tagroadtrip" /> <label for="tagroadtrip">roadtrip</label>
          <input type="checkbox" name="as:tag[]" value="boat" id="tagboat" /> <label for="tagboat">boat</label>
          <input type="checkbox" name="as:tag[]" value="ferry" id="tagferry" /> <label for="tagferry">ferry</label>
          <input type="checkbox" name="as:tag[]" value="bicycle" id="tagbicycle" /> <label for="tagbicycle">bicycle</label>
        </p>
        <p><label>Published: </label>
          <select name="year" id="year">
            <option value="2016" selected>2016</option>
            <option value="2016">2015</option>
          </select>
          <select name="month" id="month">
            <?for($i=1;$i<=12;$i++):?>
              <option value="<?=date("m", strtotime("2016-$i-01"))?>"<?=(date("n") == $i) ? " selected" : ""?>><?=date("M", strtotime("2016-$i-01"))?></option>
            <?endfor?>
          </select>
          <select name="day" id="day">
            <?for($i=1;$i<=31;$i++):?>
              <option value="<?=date("d", strtotime("2016-01-$i"))?>"<?=(date("j") == $i) ? " selected" : ""?>><?=date("d", strtotime("2016-01-$i"))?></option>
            <?endfor?>
          </select>
          <input type="text" name="time" id="time" value="<?=date("H:i:s")?>" />
          <input type="text" name="zone" id="zone" value="<?=date("P")?>" />
        </p>
      </form>
      
      <div class="color3-bg inner">
        <?if(isset($_SESSION['me'])):?>
          <p class="wee">You are logged in as <strong><?=$_SESSION['me']?></strong> <a href="?logout=1">Logout</a></p>
        <?else:?>
          <form action="https://indieauth.com/auth" method="get" class="inner clearfix">
            <label for="indie_auth_url">Domain:</label>
            <input id="indie_auth_url" type="text" name="me" placeholder="yourdomain.com" />
            <input type="submit" value="signin" />
            <input type="hidden" name="client_id" value="http://rhiaro.co.uk" />
            <input type="hidden" name="redirect_uri" value="<?=$base?>" />
            <input type="hidden" name="state" value="<?=$base?>" />
            <input type="hidden" name="scope" value="post" />
          </form>
        <?endif?>
        
        <h2>Customise</h2>
        <h3>Feed</h3>
        <form method="post" class="inner wee clearfix">
          <p>If you have a public feed of calendary posts, enter the URL here.</p>
          <label for="feed_source">URL of a list of feed:</label>
          <input id="feed_source" name="feed_source" value="<?=isset($_SESSION['feed_source']) ? $_SESSION['feed_source'] : ""?>" />
          <input type="submit" value="Fetch" /> <a href="?reset=feed">Reset</a>
        </form>
        <h3>Post...</h3>
        <form method="post" class="inner wee clearfix">
          <select name="posttype">
            <option value="as2" selected>AS2 JSON</option>
            <option value="mp" disabled>Micropub (form-encoded)</option>
            <option value="mp" disabled>Micropub (JSON)</option>
            <option value="ttl" disabled>Turtle</option>
          </select>
          <input type="submit" value="Save" />
        </form>
      </div>
    </main>
  </body>
</html>