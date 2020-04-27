<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Poodll Wordcards
 *
 * @package    mod_wordcards
 * @copyright  2015 Justin Hunt (poodllsupport@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
 namespace mod_wordcards;
defined('MOODLE_INTERNAL') || die();

use \mod_wordcards\constants;


/**
 * Functions used generally across this mod
 *
 * @package    mod_wordcards
 * @copyright  2015 Justin Hunt (poodllsupport@gmail.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class utils{



    //are we willing and able to transcribe submissions?
    public static function can_transcribe($instance)
    {
        //we default to true
        //but it only takes one no ....
        $ret = true;

        //The regions that can transcribe
        switch($instance->region){
            default:
                $ret = true;
        }

        //if user disables ai, we do not transcribe
        if(!$instance->enableai){
            $ret =false;
        }

        return $ret;
    }

    //convert a phrase or word to a series of phonetic characters that we can use to compare text/spoken
    public static function convert_to_phonetic($phrase,$language){

        switch($language){
            case 'en':
                $phonetic = metaphone($phrase);
                break;
            case 'ja':
            default:
                $phonetic = $phrase;
        }
        return $phonetic;
    }

    public static function update_stepgrade($modid,$correct){
        global $DB,$USER;
        $mod = \mod_wordcards_module::get_by_modid($modid);
        $record = $DB->get_record(constants::M_ATTEMPTSTABLE, ['modid' => $modid, 'userid' => $USER->id]);
        if (!$record) {return false;}
        $field=false;
        $termcount=0;
        switch($record->state){
            case \mod_wordcards_module::STATE_STEP1:
                $termcount=$mod->get_mod()->step1termcount;
                $field = 'grade1';
                break;
            case \mod_wordcards_module::STATE_STEP2:
                $termcount=$mod->get_mod()->step2termcount;
                $field = 'grade2';
                break;
            case \mod_wordcards_module::STATE_STEP3:
                $termcount=$mod->get_mod()->step3termcount;
                $field = 'grade3';
                break;
            case \mod_wordcards_module::STATE_STEP4:
                $termcount=$mod->get_mod()->step4termcount;
                $field = 'grade4';
                break;
            case \mod_wordcards_module::STATE_STEP5:
                $termcount=$mod->get_mod()->step5termcount;
                $field = 'grade5';
                break;
            case \mod_wordcards_module::STATE_END:
            case \mod_wordcards_module::STATE_TERMS:
            default:
                //do nothing
                break;
        }
        if($field && $termcount && ($termcount>=$correct)){
            $grade = ROUND(($correct / $termcount) * 100, 0);
            $DB->set_field(constants::M_ATTEMPTSTABLE,$field,$grade,array('userid'=>$USER->id,'modid'=>$modid));
        }
        return true;
    }

    public static function update_finalgrade($modid){
        global $DB,$USER;

        $mod = \mod_wordcards_module::get_by_modid($modid);
        $record = $DB->get_record(constants::M_ATTEMPTSTABLE, ['modid' => $modid, 'userid' => $USER->id]);
        if (!$record) {return false;}
        //one attempt and thats all for grading sorry
        if ($record->totalgrade > 0 ) {return true;}
        $states = array(\mod_wordcards_module::STATE_STEP1,\mod_wordcards_module::STATE_STEP2,\mod_wordcards_module::STATE_STEP3,
                \mod_wordcards_module::STATE_STEP4,\mod_wordcards_module::STATE_STEP5);

        $totalgrade=0;
        $totalsteps=0;
        foreach($states as $state) {
            switch ($state) {
                case \mod_wordcards_module::STATE_STEP1:
                    $termcount = $mod->get_mod()->step1termcount;
                    $grade= $record->grade1;
                    break;
                case \mod_wordcards_module::STATE_STEP2:
                    $termcount = $mod->get_mod()->step2termcount;
                    $grade= $record->grade2;
                    break;
                case \mod_wordcards_module::STATE_STEP3:
                    $termcount = $mod->get_mod()->step3termcount;
                    $grade= $record->grade3;
                    break;
                case \mod_wordcards_module::STATE_STEP4:
                    $termcount = $mod->get_mod()->step4termcount;
                    $grade= $record->grade4;
                    break;
                case \mod_wordcards_module::STATE_STEP5:
                    $termcount = $mod->get_mod()->step5termcount;
                    $grade= $record->grade5;
                    break;
                case \mod_wordcards_module::STATE_END:
                case \mod_wordcards_module::STATE_TERMS:
                default:
                    $grade=0;
                    $termcount=0;
                    break;
            }
            if($termcount>0){
                $totalsteps ++;
                $totalgrade += $grade;
            }
        }
        if($totalsteps>0) {
            $grade = ROUND(($totalgrade / $totalsteps), 0);
            $DB->set_field(constants::M_ATTEMPTSTABLE, 'totalgrade', $grade,array('userid'=>$USER->id,'modid'=>$modid));
            wordcards_update_grades($mod->get_mod(), $USER->id, false);
        }
        return true;
    }


    //we use curl to fetch transcripts from AWS and Tokens from cloudpoodll
    //this is our helper
    //we use curl to fetch transcripts from AWS and Tokens from cloudpoodll
    //this is our helper
    public static function curl_fetch($url,$postdata=false)
    {
        global $CFG;

        require_once($CFG->libdir.'/filelib.php');
        $curl = new \curl();

        $result = $curl->get($url, $postdata);
        return $result;
    }

    //This is called from the settings page and we do not want to make calls out to cloud.poodll.com on settings
    //page load, for performance and stability issues. So if the cache is empty and/or no token, we just show a
    //"refresh token" links
    public static function fetch_token_for_display($apiuser,$apisecret){
       global $CFG;

       //First check that we have an API id and secret
        //refresh token
        $refresh = \html_writer::link($CFG->wwwroot . '/mod/wordcards/refreshtoken.php',
                get_string('refreshtoken',constants::M_COMPONENT)) . '<br>';


        $message = '';
        $apiuser = trim($apiuser);
        $apisecret = trim($apisecret);
        if(empty($apiuser)){
           $message .= get_string('noapiuser',constants::M_COMPONENT) . '<br>';
       }
        if(empty($apisecret)){
            $message .= get_string('noapisecret',constants::M_COMPONENT);
        }

        if(!empty($message)){
            return $refresh . $message;
        }

        //Fetch from cache and process the results and display
        $cache = \cache::make_from_params(\cache_store::MODE_APPLICATION, constants::M_COMPONENT, 'token');
        $tokenobject = $cache->get('recentpoodlltoken');

        //if we have no token object the creds were wrong ... or something
        if(!($tokenobject)){
            $message = get_string('notokenincache',constants::M_COMPONENT);
            //if we have an object but its no good, creds werer wrong ..or something
        }elseif(!property_exists($tokenobject,'token') || empty($tokenobject->token)){
            $message = get_string('credentialsinvalid',constants::M_COMPONENT);
        //if we do not have subs, then we are on a very old token or something is wrong, just get out of here.
        }elseif(!property_exists($tokenobject,'subs')){
            $message = 'No subscriptions found at all';
        }
        if(!empty($message)){
            return $refresh . $message;
        }

        //we have enough info to display a report. Lets go.
        foreach ($tokenobject->subs as $sub){
            $sub->expiredate = date('d/m/Y',$sub->expiredate);
            $message .= get_string('displaysubs',constants::M_COMPONENT, $sub) . '<br>';
        }

        //Is app authorised
        if(in_array(constants::M_COMPONENT,$tokenobject->apps) &&
         self::is_site_registered($tokenobject->sites,true)){
            $message .= get_string('appauthorised',constants::M_COMPONENT) . '<br>';
        }else{
            $message .= get_string('appnotauthorised',constants::M_COMPONENT) . '<br>';
        }

        return $refresh . $message;

    }

    //We need a Poodll token to make all this recording and transcripts happen
    public static function fetch_token($apiuser, $apisecret, $force=false)
    {

        $cache = \cache::make_from_params(\cache_store::MODE_APPLICATION, constants::M_COMPONENT, 'token');
        $tokenobject = $cache->get('recentpoodlltoken');
        $tokenuser = $cache->get('recentpoodlluser');
        $apiuser = trim($apiuser);
        $apisecret = trim($apisecret);

        //if we got a token and its less than expiry time
        // use the cached one
        if($tokenobject && $tokenuser && $tokenuser==$apiuser && !$force){
            if($tokenobject->validuntil == 0 || $tokenobject->validuntil > time()){
                return $tokenobject->token;
            }
        }

        // Send the request & save response to $resp
        $token_url ="https://cloud.poodll.com/local/cpapi/poodlltoken.php";
        $postdata = array(
            'username' => $apiuser,
            'password' => $apisecret,
            'service'=>'cloud_poodll'
        );
        $token_response = self::curl_fetch($token_url,$postdata);
        if ($token_response) {
            $resp_object = json_decode($token_response);
            if($resp_object && property_exists($resp_object,'token')) {
                $token = $resp_object->token;
                //store the expiry timestamp and adjust it for diffs between our server times
                if($resp_object->validuntil) {
                    $validuntil = $resp_object->validuntil - ($resp_object->poodlltime - time());
                    //we refresh one hour out, to prevent any overlap
                    $validuntil = $validuntil - (1 * HOURSECS);
                }else{
                    $validuntil = 0;
                }

                //cache the token
                $tokenobject = new \stdClass();
                $tokenobject->token = $token;
                $tokenobject->validuntil = $validuntil;
                $tokenobject->subs=false;
                $tokenobject->apps=false;
                $tokenobject->sites=false;
                if(property_exists($resp_object,'subs')){
                    $tokenobject->subs = $resp_object->subs;
                }
                if(property_exists($resp_object,'apps')){
                    $tokenobject->apps = $resp_object->apps;
                }
                if(property_exists($resp_object,'sites')){
                    $tokenobject->sites = $resp_object->sites;
                }
                if(property_exists($resp_object,'awsaccesssecret')){
                    $tokenobject->awsaccesssecret = $resp_object->awsaccesssecret;
                }
                if(property_exists($resp_object,'awsaccessid')){
                    $tokenobject->awsaccessid = $resp_object->awsaccessid;
                }

                $cache->set('recentpoodlltoken', $tokenobject);
                $cache->set('recentpoodlluser', $apiuser);

            }else{
                $token = '';
                if($resp_object && property_exists($resp_object,'error')) {
                    //ERROR = $resp_object->error
                }
            }
        }else{
            $token='';
        }
        return $token;
    }

    //check site URL is actually registered
    static function is_site_registered($sites, $wildcardok = true) {
        global $CFG;

        foreach($sites as $site) {

            //get arrays of the wwwroot and registered url
            //just in case, lowercase'ify them
            $thewwwroot = strtolower($CFG->wwwroot);
            $theregisteredurl = strtolower($site);
            $theregisteredurl = trim($theregisteredurl);

            //add http:// or https:// to URLs that do not have it
            if (strpos($theregisteredurl, 'https://') !== 0 &&
                    strpos($theregisteredurl, 'http://') !== 0) {
                $theregisteredurl = 'https://' . $theregisteredurl;
            }

            //if neither parsed successfully, that a no straight up
            $wwwroot_bits = parse_url($thewwwroot);
            $registered_bits = parse_url($theregisteredurl);
            if (!$wwwroot_bits || !$registered_bits) {
                //this is not a match
                continue;
            }

            //get the subdomain widlcard address, ie *.a.b.c.d.com
            $wildcard_subdomain_wwwroot = '';
            if (array_key_exists('host', $wwwroot_bits)) {
                $wildcardparts = explode('.', $wwwroot_bits['host']);
                $wildcardparts[0] = '*';
                $wildcard_subdomain_wwwroot = implode('.', $wildcardparts);
            } else {
                //this is not a match
                continue;
            }

            //match either the exact domain or the wildcard domain or fail
            if (array_key_exists('host', $registered_bits)) {
                //this will cover exact matches and path matches
                if ($registered_bits['host'] === $wwwroot_bits['host']) {
                    //this is a match
                    return true;
                    //this will cover subdomain matches
                } else if (($registered_bits['host'] === $wildcard_subdomain_wwwroot) && $wildcardok) {
                    //yay we are registered!!!!
                    return true;
                } else {
                    //not a match
                    continue;
                }
            } else {
                //not a match
                return false;
            }
        }
        return false;
    }

    //check token and tokenobject(from cache)
    //return error message or blank if its all ok
    public static function fetch_token_error($token){
        global $CFG;

        //check token authenticated
        if(empty($token)) {
            $message = get_string('novalidcredentials', constants::M_COMPONENT,
                    $CFG->wwwroot . constants::M_PLUGINSETTINGS);
            return $message;
        }

        // Fetch from cache and process the results and display.
        $cache = \cache::make_from_params(\cache_store::MODE_APPLICATION, constants::M_COMPONENT, 'token');
        $tokenobject = $cache->get('recentpoodlltoken');

        //we should not get here if there is no token, but lets gracefully die, [v unlikely]
        if (!($tokenobject)) {
            $message = get_string('notokenincache', constants::M_COMPONENT);
            return $message;
        }

        //We have an object but its no good, creds were wrong ..or something. [v unlikely]
        if (!property_exists($tokenobject, 'token') || empty($tokenobject->token)) {
            $message = get_string('credentialsinvalid', constants::M_COMPONENT);
            return $message;
        }
        // if we do not have subs.
        if (!property_exists($tokenobject, 'subs')) {
            $message = get_string('nosubscriptions', constants::M_COMPONENT);
            return $message;
        }
        // Is app authorised?
        if (!property_exists($tokenobject, 'apps') || !in_array(constants::M_COMPONENT, $tokenobject->apps)) {
            $message = get_string('appnotauthorised', constants::M_COMPONENT);
            return $message;
        }

        //just return empty if there is no error.
        return '';
    }


  public static function get_region_options(){
      return array(
        "useast1" => get_string("useast1",constants::M_COMPONENT),
          "tokyo" => get_string("tokyo",constants::M_COMPONENT),
          "sydney" => get_string("sydney",constants::M_COMPONENT),
          "dublin" => get_string("dublin",constants::M_COMPONENT),
          "ottawa" => get_string("ottawa",constants::M_COMPONENT),
          "frankfurt" => get_string("frankfurt",constants::M_COMPONENT),
          "london" => get_string("london",constants::M_COMPONENT),
          "saopaulo" => get_string("saopaulo",constants::M_COMPONENT),
          "singapore" => get_string("singapore",constants::M_COMPONENT),
          "mumbai" => get_string("mumbai",constants::M_COMPONENT)
      );
  }

    public static function translate_region($key){
        switch($key){
            case "useast1": return "us-east-1";
            case "tokyo": return "ap-northeast-1";
            case "sydney": return "ap-southeast-2";
            case "dublin": return "eu-east-1";
            case "ottawa": return "us-east-1";
            case "frankfurt": return "eu-central-2";
            case "london": return "us-east-1";
            case "saopaulo": return "sa-east-1";
            case "singapore": return "us-east-1";
            case "mumbai": return "us-east-1";
        }
    }

    public static function get_timelimit_options(){
        return array(
            0 => get_string("notimelimit",constants::M_COMPONENT),
            15 => get_string("xsecs",constants::M_COMPONENT,'15'),
            30 => get_string("xsecs",constants::M_COMPONENT,'30'),
            45 => get_string("xsecs",constants::M_COMPONENT,'45'),
            60 => get_string("onemin",constants::M_COMPONENT),
            90 => get_string("oneminxsecs",constants::M_COMPONENT,'30'),
            120 => get_string("xmins",constants::M_COMPONENT,'2'),
            150 => get_string("xminsecs",constants::M_COMPONENT,array('minutes'=>2,'seconds'=>30)),
            180 => get_string("xmins",constants::M_COMPONENT,'3')
        );
    }

  public static function get_expiredays_options(){
      return array(
          "1"=>"1",
          "3"=>"3",
          "7"=>"7",
          "30"=>"30",
          "90"=>"90",
          "180"=>"180",
          "365"=>"365",
          "730"=>"730",
          "9999"=>get_string('forever',constants::M_COMPONENT)
      );
  }

    public static function fetch_options_transcribers() {
        $options = array(constants::TRANSCRIBER_AMAZONTRANSCRIBE => get_string("transcriber_amazontranscribe", constants::M_COMPONENT),
                constants::TRANSCRIBER_GOOGLECLOUDSPEECH => get_string("transcriber_googlecloud", constants::M_COMPONENT));
        return $options;
    }

    public static function fetch_filemanager_opts($mediatype){
      global $CFG;
        $file_external = 1;
        $file_internal = 2;
        return array('subdirs' => 0, 'maxbytes' => $CFG->maxbytes, 'areamaxbytes' => 10485760, 'maxfiles' => 1,
                'accepted_types' => array($mediatype), 'return_types'=> $file_internal | $file_external);
    }

    //see if this is truly json or some error
    public static function is_json($string) {
        if (!$string) {
            return false;
        }
        if (empty($string)) {
            return false;
        }
        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }

    //fetch the MP3 URL of the text we want transcribed
    public static function fetch_polly_url($token,$region,$speaktext,$texttype, $voice) {
        global $USER;

        //The REST API we are calling
        $functionname = 'local_cpapi_fetch_polly_url';

        //log.debug(params);
        $params = array();
        $params['wstoken'] = $token;
        $params['wsfunction'] = $functionname;
        $params['moodlewsrestformat'] = 'json';
        $params['text'] = urlencode($speaktext);
        $params['texttype'] = $texttype;
        $params['voice'] = $voice;
        $params['appid'] = 'mod_readaloud';
        $params['owner'] = hash('md5',$USER->username);
        $params['region'] = $region;
        $serverurl = 'https://cloud.poodll.com/webservice/rest/server.php';
        $response = self::curl_fetch($serverurl, $params);
        if (!self::is_json($response)) {
            return false;
        }
        $payloadobject = json_decode($response);

        //returnCode > 0  indicates an error
        if ($payloadobject->returnCode > 0) {
            return false;
            //if all good, then lets do the embed
        } else if ($payloadobject->returnCode === 0) {
            $pollyurl = $payloadobject->returnMessage;
            return $pollyurl;
        } else {
            return false;
        }
    }

  public static function fetch_auto_voice($langcode){
        $voices = self::get_tts_voices($langcode);
        $autoindex = array_rand($voices);
        return $voices[$autoindex];
  }

  public static function get_tts_voices($langcode){
      $alllang= array(
              constants::M_LANG_ARAE => ['Zeina'],
          //constants::M_LANG_ARSA => [],
              constants::M_LANG_DEDE => ['Hans'=>'Hans','Marlene'=>'Marlene', 'Vicki'=>'Vicki'],
          //constants::M_LANG_DECH => [],
              constants::M_LANG_ENUS => ['Joey'=>'Joey','Justin'=>'Justin','Matthew'=>'Matthew','Ivy'=>'Ivy',
                      'Joanna'=>'Joanna','Kendra'=>'Kendra','Kimberly'=>'Kimberly','Salli'=>'Salli'],
              constants::M_LANG_ENGB => ['Brian'=>'Brian','Amy'=>'Amy', 'Emma'=>'Emma'],
              constants::M_LANG_ENAU => ['Russell'=>'Russell','Nicole'=>'Nicole'],
              constants::M_LANG_ENIN => ['Aditi'=>'Aditi', 'Raveena'=>'Raveena'],
          // constants::M_LANG_ENIE => [],
              constants::M_LANG_ENWL => ["Geraint"=>"Geraint"],
          // constants::M_LANG_ENAB => [],
              constants::M_LANG_ESUS => ['Miguel'=>'Miguel','Penelope'=>'Penelope'],
              constants::M_LANG_ESES => [ 'Enrique'=>'Enrique', 'Conchita'=>'Conchita', 'Lucia'=>'Lucia'],
          //constants::M_LANG_FAIR => [],
              constants::M_LANG_FRCA => ['Chantal'=>'Chantal'],
              constants::M_LANG_FRFR => ['Mathieu'=>'Mathieu','Celine'=>'Celine', 'Léa'=>'Léa'],
              constants::M_LANG_HIIN => ["Aditi"=>"Aditi"],
          //constants::M_LANG_HEIL => [],
          //constants::M_LANG_IDID => [],
              constants::M_LANG_ITIT => ['Carla'=>'Carla',  'Bianca'=>'Bianca', 'Giorgio'=>'Giorgio'],
              constants::M_LANG_JAJP => ['Takumi'=>'Takumi','Mizuki'=>'Mizuki'],
              constants::M_LANG_KOKR => ['Seoyan'=>'Seoyan'],
          //constants::M_LANG_MSMY => [],
              constants::M_LANG_NLNL => ["Ruben"=>"Ruben","Lotte"=>"Lotte"],
              constants::M_LANG_PTBR => ['Ricardo'=>'Ricardo', 'Vitoria'=>'Vitoria'],
              constants::M_LANG_PTPT => ["Ines"=>"Ines",'Cristiano'=>'Cristiano'],
              constants::M_LANG_RURU => ["Tatyana"=>"Tatyana","Maxim"=>"Maxim"],
          //constants::M_LANG_TAIN => [],
          //constants::M_LANG_TEIN => [],
              constants::M_LANG_TRTR => ['Filiz'=>'Filiz'],
              constants::M_LANG_ZHCN => ['Zhiyu']
      );
      if(array_key_exists($langcode,$alllang)) {
          return $alllang[$langcode];
      }else{
          return $alllang[constants::M_LANG_ENUS];
      }
    /*
      {"lang": "English(US)", "voices":  [{name: 'Joey', mf: 'm'},{name: 'Justin', mf: 'm'},{name: 'Matthew', mf: 'm'},{name: 'Ivy', mf: 'f'},{name: 'Joanna', mf: 'f'},{name: 'Kendra', mf: 'f'},{name: 'Kimberly', mf: 'f'},{name: 'Salli', mf: 'f'}]},
      {"lang": "English(GB)", "voices":  [{name: 'Brian', mf: 'm'},{name: 'Amy', mf: 'f'},{name: 'Emma', mf: 'f'}]},
      {"lang": "English(AU)", "voices": [{name: 'Russell', mf: 'm'},{name: 'Nicole', mf: 'f'}]},
      {"lang": "English(IN)", "voices":  [{name: 'Aditi', mf: 'm'},{name: 'Raveena', mf: 'f'}]},
      {"lang": "English(WELSH)", "voices":  [{name: 'Geraint', mf: 'm'}]},
      {"lang": "Danish", "voices":  [{name: 'Mads', mf: 'm'},{name: 'Naja', mf: 'f'}]},
      {"lang": "Dutch", "voices":  [{name: 'Ruben', mf: 'm'},{name: 'Lotte', mf: 'f'}]},
      {"lang": "French(FR)", "voices":  [{name: 'Mathieu', mf: 'm'},{name: 'Celine', mf: 'f'},{name: 'Léa', mf: 'f'}]},
      {"lang": "French(CA)", "voices":  [{name: 'Chantal', mf: 'm'}]},
      {"lang": "German", "voices":  [{name: 'Hans', mf: 'm'},{name: 'Marlene', mf: 'f'},{name: 'Vicki', mf: 'f'}]},
      {"lang": "Icelandic", "voices":  [{name: 'Karl', mf: 'm'},{name: 'Dora', mf: 'f'}]},
      {"lang": "Italian", "voices":  [{name: 'Carla', mf: 'f'},{name: 'Bianca', mf: 'f'},{name: 'Giorgio', mf: 'm'}]},
      {"lang": "Japanese", "voices":  [{name: 'Takumi', mf: 'm'},{name: 'Mizuki', mf: 'f'}]},
      {"lang": "Korean", "voices":  [{name: 'Seoyan', mf: 'f'}]},
      {"lang": "Norwegian", "voices":  [{name: 'Liv', mf: 'f'}]},
      {"lang": "Polish", "voices":  [{name: 'Jacek', mf: 'm'},{name: 'Jan', mf: 'm'},{name: 'Maja', mf: 'f'},{name: 'Ewa', mf: 'f'}]},
      {"lang": "Portugese(BR)", "voices":  [{name: 'Ricardo', mf: 'm'},{name: 'Vitoria', mf: 'f'}]},
      {"lang": "Portugese(PT)", "voices":  [{name: 'Cristiano', mf: 'm'},{name: 'Ines', mf: 'f'}]},
      {"lang": "Romanian", "voices":  [{name: 'Carmen', mf: 'f'}]},
      {"lang": "Russian", "voices":  [{name: 'Maxim', mf: 'm'},{name: 'Tatyana', mf: 'f'}]},
      {"lang": "Spanish(ES)", "voices":  [{name: 'Enrique', mf: 'm'},{name: 'Conchita', mf: 'f'},{name: 'Lucia', mf: 'f'}]},
      {"lang": "Spanish(US)", "voices":  [{name: 'Miguel', mf: 'm'},{name: 'Penelope', mf: 'f'}]},
      {"lang": "Swedish", "voices":  [{name: 'Astrid', mf: 'f'}]},
      {"lang": "Turkish", "voices":  [{name: 'Filiz', mf: 'f'}]},
      {"lang": "Welsh", "voices":  [{name: 'Gwyneth', mf: 'f'}]},
    */

  }

  /* An activity typoe will be eith practice or review */
    public static function fetch_activity_tablabel($activitytype){
      switch($activitytype){
          case \mod_wordcards_module::PRACTICETYPE_MATCHSELECT:
          case \mod_wordcards_module::PRACTICETYPE_MATCHTYPE:
          case \mod_wordcards_module::PRACTICETYPE_DICTATION:
          case \mod_wordcards_module::PRACTICETYPE_SPEECHCARDS:
              return get_string('practice','mod_wordcards') ;
          case \mod_wordcards_module::PRACTICETYPE_MATCHSELECT_REV:
          case \mod_wordcards_module::PRACTICETYPE_MATCHTYPE_REV:
          case \mod_wordcards_module::PRACTICETYPE_DICTATION_REV:
          case \mod_wordcards_module::PRACTICETYPE_SPEECHCARDS_REV:
              return get_string('review','mod_wordcards');

      }
    }

    /* An activity typoe will be eith practice or review */
    public static function is_review_activity($activitytype){
        switch($activitytype){
            case \mod_wordcards_module::PRACTICETYPE_MATCHSELECT:
            case \mod_wordcards_module::PRACTICETYPE_MATCHTYPE:
            case \mod_wordcards_module::PRACTICETYPE_DICTATION:
            case \mod_wordcards_module::PRACTICETYPE_SPEECHCARDS:
                return false;
            case \mod_wordcards_module::PRACTICETYPE_MATCHSELECT_REV:
            case \mod_wordcards_module::PRACTICETYPE_MATCHTYPE_REV:
            case \mod_wordcards_module::PRACTICETYPE_DICTATION_REV:
            case \mod_wordcards_module::PRACTICETYPE_SPEECHCARDS_REV:
                return true;

        }
    }

    /* Each activity shows an icon on the tab tree */
    public static function fetch_activity_tabicon($activitytype){
        switch($activitytype){
            case \mod_wordcards_module::PRACTICETYPE_MATCHSELECT:
            case \mod_wordcards_module::PRACTICETYPE_MATCHSELECT_REV:
                return 'fa-bars';

            case \mod_wordcards_module::PRACTICETYPE_MATCHTYPE:
            case \mod_wordcards_module::PRACTICETYPE_MATCHTYPE_REV:
                return 'fa-keyboard-o';

            case \mod_wordcards_module::PRACTICETYPE_DICTATION:
            case \mod_wordcards_module::PRACTICETYPE_DICTATION_REV:
                return 'fa-headphones';

            case \mod_wordcards_module::PRACTICETYPE_SPEECHCARDS:
            case \mod_wordcards_module::PRACTICETYPE_SPEECHCARDS_REV:
                return 'fa-comment-o';

            default:
                return 'fa-dot-circle-o';
        }
    }

  public static function get_practicetype_options($wordpool=false){
      $none =  array(\mod_wordcards_module::PRACTICETYPE_NONE => get_string('title_noactivity', 'mod_wordcards'));
      $learnoptions = [
              \mod_wordcards_module::PRACTICETYPE_MATCHSELECT => get_string('title_matchselect', 'mod_wordcards'),
              \mod_wordcards_module::PRACTICETYPE_MATCHTYPE => get_string('title_matchtype', 'mod_wordcards'),
              \mod_wordcards_module::PRACTICETYPE_DICTATION => get_string('title_dictation', 'mod_wordcards'),
              \mod_wordcards_module::PRACTICETYPE_SPEECHCARDS => get_string('title_speechcards', 'mod_wordcards')
      ];

        $reviewoptions = [
            \mod_wordcards_module::PRACTICETYPE_MATCHSELECT_REV => get_string('title_matchselect_rev', 'mod_wordcards'),
            \mod_wordcards_module::PRACTICETYPE_MATCHTYPE_REV => get_string('title_matchtype_rev', 'mod_wordcards'),
            \mod_wordcards_module::PRACTICETYPE_DICTATION_REV => get_string('title_dictation_rev', 'mod_wordcards'),
            \mod_wordcards_module::PRACTICETYPE_SPEECHCARDS_REV => get_string('title_speechcards_rev', 'mod_wordcards')
            ];

      if($wordpool===\mod_wordcards_module::WORDPOOL_LEARN){
          $options=$learnoptions;
      }else{
          $options = array_merge($none,$learnoptions,$reviewoptions);
      }
      return $options;
  }

   public static function get_lang_options(){
       return array(
               constants::M_LANG_ARAE => get_string('ar-ae', constants::M_COMPONENT),
               constants::M_LANG_ARSA => get_string('ar-sa', constants::M_COMPONENT),
               constants::M_LANG_DEDE => get_string('de-de', constants::M_COMPONENT),
               constants::M_LANG_DECH => get_string('de-ch', constants::M_COMPONENT),
               constants::M_LANG_ENUS => get_string('en-us', constants::M_COMPONENT),
               constants::M_LANG_ENGB => get_string('en-gb', constants::M_COMPONENT),
               constants::M_LANG_ENAU => get_string('en-au', constants::M_COMPONENT),
               constants::M_LANG_ENIN => get_string('en-in', constants::M_COMPONENT),
               constants::M_LANG_ENIE => get_string('en-ie', constants::M_COMPONENT),
               constants::M_LANG_ENWL => get_string('en-wl', constants::M_COMPONENT),
               constants::M_LANG_ENAB => get_string('en-ab', constants::M_COMPONENT),
               constants::M_LANG_ESUS => get_string('es-us', constants::M_COMPONENT),
               constants::M_LANG_ESES => get_string('es-es', constants::M_COMPONENT),
               constants::M_LANG_FAIR => get_string('fa-ir', constants::M_COMPONENT),
               constants::M_LANG_FRCA => get_string('fr-ca', constants::M_COMPONENT),
               constants::M_LANG_FRFR => get_string('fr-fr', constants::M_COMPONENT),
               constants::M_LANG_HIIN => get_string('hi-in', constants::M_COMPONENT),
               constants::M_LANG_HEIL => get_string('he-il', constants::M_COMPONENT),
               constants::M_LANG_IDID => get_string('id-id', constants::M_COMPONENT),
               constants::M_LANG_ITIT => get_string('it-it', constants::M_COMPONENT),
               constants::M_LANG_JAJP => get_string('ja-jp', constants::M_COMPONENT),
               constants::M_LANG_KOKR => get_string('ko-kr', constants::M_COMPONENT),
               constants::M_LANG_MSMY => get_string('ms-my', constants::M_COMPONENT),
               constants::M_LANG_NLNL => get_string('nl-nl', constants::M_COMPONENT),
               constants::M_LANG_PTBR => get_string('pt-br', constants::M_COMPONENT),
               constants::M_LANG_PTPT => get_string('pt-pt', constants::M_COMPONENT),
               constants::M_LANG_RURU => get_string('ru-ru', constants::M_COMPONENT),
               constants::M_LANG_TAIN => get_string('ta-in', constants::M_COMPONENT),
               constants::M_LANG_TEIN => get_string('te-in', constants::M_COMPONENT),
               constants::M_LANG_TRTR => get_string('tr-tr', constants::M_COMPONENT),
               constants::M_LANG_ZHCN => get_string('zh-cn', constants::M_COMPONENT)
       );
	/*
      return array(
			"none"=>"No TTS",
			"af"=>"Afrikaans", 
			"sq"=>"Albanian", 
			"am"=>"Amharic", 
			"ar"=>"Arabic", 
			"hy"=>"Armenian", 
			"az"=>"Azerbaijani", 
			"eu"=>"Basque", 
			"be"=>"Belarusian", 
			"bn"=>"Bengali", 
			"bh"=>"Bihari", 
			"bs"=>"Bosnian", 
			"br"=>"Breton", 
			"bg"=>"Bulgarian", 
			"km"=>"Cambodian", 
			"ca"=>"Catalan", 
			"zh-CN"=>"Chinese (Simplified)", 
			"zh-TW"=>"Chinese (Traditional)", 
			"co"=>"Corsican", 
			"hr"=>"Croatian", 
			"cs"=>"Czech", 
			"da"=>"Danish", 
			"nl"=>"Dutch", 
			"en"=>"English", 
			"eo"=>"Esperanto", 
			"et"=>"Estonian", 
			"fo"=>"Faroese", 
			"tl"=>"Filipino", 
			"fi"=>"Finnish", 
			"fr"=>"French", 
			"fy"=>"Frisian", 
			"gl"=>"Galician", 
			"ka"=>"Georgian", 
			"de"=>"German", 
			"el"=>"Greek", 
			"gn"=>"Guarani", 
			"gu"=>"Gujarati", 
			"xx-hacker"=>"Hacker", 
			"ha"=>"Hausa", 
			"iw"=>"Hebrew", 
			"hi"=>"Hindi", 
			"hu"=>"Hungarian", 
			"is"=>"Icelandic", 
			"id"=>"Indonesian", 
			"ia"=>"Interlingua", 
			"ga"=>"Irish", 
			"it"=>"Italian", 
			"ja"=>"Japanese", 
			"jw"=>"Javanese", 
			"kn"=>"Kannada", 
			"kk"=>"Kazakh", 
			"rw"=>"Kinyarwanda", 
			"rn"=>"Kirundi", 
			"xx-klingon"=>"Klingon", 
			"ko"=>"Korean", 
			"ku"=>"Kurdish", 
			"ky"=>"Kyrgyz", 
			"lo"=>"Laothian", 
			"la"=>"Latin", 
			"lv"=>"Latvian", 
			"ln"=>"Lingala", 
			"lt"=>"Lithuanian", 
			"mk"=>"Macedonian", 
			"mg"=>"Malagasy", 
			"ms"=>"Malay", 
			"ml"=>"Malayalam", 
			"mt"=>"Maltese", 
			"mi"=>"Maori", 
			"mr"=>"Marathi", 
			"mo"=>"Moldavian", 
			"mn"=>"Mongolian", 
			"sr-ME"=>"Montenegrin", 
			"ne"=>"Nepali", 
			"no"=>"Norwegian", 
			"nn"=>"Norwegian(Nynorsk)", 
			"oc"=>"Occitan", 
			"or"=>"Oriya", 
			"om"=>"Oromo", 
			"ps"=>"Pashto", 
			"fa"=>"Persian", 
			"xx-pirate"=>"Pirate", 
			"pl"=>"Polish", 
			"pt-BR"=>"Portuguese(Brazil)", 
			"pt-PT"=>"Portuguese(Portugal)", 
			"pa"=>"Punjabi", 
			"qu"=>"Quechua", 
			"ro"=>"Romanian", 
			"rm"=>"Romansh", 
			"ru"=>"Russian", 
			"gd"=>"Scots Gaelic", 
			"sr"=>"Serbian", 
			"sh"=>"Serbo-Croatian", 
			"st"=>"Sesotho", 
			"sn"=>"Shona", 
			"sd"=>"Sindhi", 
			"si"=>"Sinhalese", 
			"sk"=>"Slovak", 
			"sl"=>"Slovenian", 
			"so"=>"Somali", 
			"es"=>"Spanish", 
			"su"=>"Sundanese", 
			"sw"=>"Swahili", 
			"sv"=>"Swedish", 
			"tg"=>"Tajik", 
			"ta"=>"Tamil", 
			"tt"=>"Tatar", 
			"te"=>"Telugu", 
			"th"=>"Thai", 
			"ti"=>"Tigrinya", 
			"to"=>"Tonga", 
			"tr"=>"Turkish", 
			"tk"=>"Turkmen", 
			"tw"=>"Twi", 
			"ug"=>"Uighur", 
			"uk"=>"Ukrainian", 
			"ur"=>"Urdu", 
			"uz"=>"Uzbek", 
			"vi"=>"Vietnamese", 
			"cy"=>"Welsh", 
			"xh"=>"Xhosa", 
			"yi"=>"Yiddish", 
			"yo"=>"Yoruba", 
			"zu"=>"Zulu"
		);
	*/
   }
}
