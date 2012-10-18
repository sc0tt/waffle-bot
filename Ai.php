<?php

class Phergie_Plugin_Ai extends Phergie_Plugin_Abstract
{
    /* DB Settings - Uses Postgresql */
    private $db_host;
    private $db_user;
    private $db_user_pass;
    private $db_name;
    private $dbh;
    
    /* Random message settings */
    private $cnt = 0; //Number of lines read since the last random blurb
    private $lmt = 0; //This is the amount of lines before blurting something out.
    
    /* Reddit settings */
    private $rEnabled;
    private $ruser;
    private $rpasswd;
    private $subreddit;
    
    /* Twitter settings */
    private $tEnabled;
    private $CONSUMER_KEY;
    private $CONSUMER_SECRET;
    private $OAUTH_TOKEN;
    private $OAUTH_SECRET;
    
    public function __construct() {
        $this->lmt = rand(100,200);
    }

    public function onLoad()
    {
        $plugins = $this->getPluginHandler();
        $plugins->getPlugin('Message');
        $plugins->getPlugin('Command');
		
		$this->db_host = $this->config['ai.settings']['db']['host'];
		$this->db_user = $this->config['ai.settings']['db']['user'];
		$this->db_user_pass = $this->config['ai.settings']['db']['pass'];
		$this->db_name = $this->config['ai.settings']['db']['name'];
		
		$this->ruser = $this->config['ai.settings']['reddit']['user'];
		$this->rpasswd = $this->config['ai.settings']['reddit']['pass'];
		$this->subreddit = $this->config['ai.settings']['reddit']['subreddit'];
        
        $rEnabled = $this->ruser == $this->rpasswd && $this->rpasswd == $this->subreddit && $this->subreddit == "" ? FALSE : TRUE;
		
		$this->CONSUMER_KEY = $this->config['ai.settings']['twitter']['CONSUMER_KEY'];
		$this->CONSUMER_SECRET = $this->config['ai.settings']['twitter']['CONSUMER_SECRET'];
		$this->OAUTH_TOKEN = $this->config['ai.settings']['twitter']['OAUTH_TOKEN'];
		$this->OAUTH_SECRET = $this->config['ai.settings']['twitter']['OAUTH_SECRET'];
        
        $tEnabled = $this->CONSUMER_KEY == $this->CONSUMER_SECRET && $this->CONSUMER_SECRET == $this->OAUTH_TOKEN && $this->OAUTH_TOKEN == $this->OAUTH_SECRET && $this->OAUTH_SECRET == "" ? FALSE : TRUE;
		
		$this->dbh = new PDO("pgsql:host=".$this->db_host.";dbname=".$this->db_name, $this->db_user, $this->db_user_pass);
    }
    
    private function in_arrayi($needle, $haystack)
    {
        for($h = 0 ; $h < count($haystack) ; $h++)
        {
            $haystack[$h] = strtolower($haystack[$h]);
        }
        return in_array(strtolower($needle),$haystack);
    }
    
    private function randomMessage($source)
    {
        if($this->cnt == $this->lmt) {
            $this->doPrivmsg("#pancakes", $this->random_string());
            $this->cnt = 0;
            $this->lmt = rand(100,200);
        }
        else {
            if($source == "#pancakes")
                $this->cnt++;
        }
    }
    
    private function readData($nick, $msg)
    {
        $data = preg_replace('/\s{2,}/', ' ', $msg); //Strip excess whitespace
        $data = explode('. ',$msg); //Every sentence that the message contains is split up.
        $keyvals = array();
        foreach($data as $txt) { 
            $txt = trim($txt); //Not sure if this is needed after the next line, but we'll leave it just in case.
            $alltxt = explode(' ', $txt); //Now we want to get words
            //5 word max chain
            if(count($alltxt) >= 5) {
                for($x = 0; $x < count($alltxt)-4; $x++) {
                    //[This is a] -> [keyval pair]
                    $key = $alltxt[$x].' '.$alltxt[$x+1].' '.$alltxt[$x+2];
                    $val = $alltxt[$x+3].' '.$alltxt[$x+4];
                    $keyvals[$key] = $val;
                    
                    //[This is] -> [a keyval pair]
                    $key = $alltxt[$x].' '.$alltxt[$x+1];
                    $val = $alltxt[$x+2].' '.$alltxt[$x+3].' '.$alltxt[$x+4];
                    $keyvals[$key] = $val;
                }
            }

            $sth = $this->dbh->prepare("INSERT INTO pairs (key, value, nick, time) VALUES (?,?,?,?);");

            foreach($keyvals as $key => $val) {
                $sth->execute(Array($key,$val,$nick,time()));
            }
        }
    }
    
    public function onPrivmsg()
    {
        $source = $this->getEvent()->getSource();
        $nick = $this->getEvent()->getNick();
        $msg = $this->plugins->message->getMessage();
  
        if($this->startsWith(strtolower($msg), "waffle-bot") && strlen($msg) > 11) //Personalized message
        {
            $this->doPrivmsg($source, "$nick: " . $this->personal_msg(substr($msg,11))); //Sends all text past "waffle-bot "
        }
        else if(strtolower(trim($msg)) == "waffle-bot") //Otherwise, check to see if it's a regular invoke
        { 
            $msg = $this->random_string();
            $this->doPrivmsg($source, $msg);
            if(rand(1,32) == 17) // 1 in 32 chance of tweeting/posting
            {
                $this->doTweet($msg); //Tweet only the above message
                $this->doReddit($msg,$this->personal_msg($msg)); //Make a reddit post with the above message as the title, then make a body based off of the title
            }
        }
        else //Read data!
        {
            if(!$this->in_arrayi(strtolower($nick), $this->config['ai.settings']['ignore'])) //Checks to make sure the user is not on the ignore list
            {
                $this->readData($nick, $msg);
            }
        }
        
        $this->randomMessage($source);
    }
    
    private function doTweet($msg)
    {
        if(!$tEnabled)
            return;
        require_once 'twitteroauth.php';
        $connection = new TwitterOAuth($this->CONSUMER_KEY, $this->CONSUMER_SECRET, $this->OAUTH_TOKEN, $this->OAUTH_SECRET);
        $content = $connection->get('account/verify_credentials');

        $connection->post('statuses/update', array('status' => $msg)); //TODO: check for > 140 characters and do something.
    }
    
    private function doReddit($title, $body)
    {
        if(!$rEnabled)
            return;
        $rurl = "http://www.reddit.com/api/login/".$this->ruser;

        $r = new HttpRequest($rurl, HttpRequest::METH_POST);
        $r->addPostFields(array('api_type' => 'json', 'user' => $this->ruser, 'passwd' => $this->rpasswd));

        try {
            $send = $r->send();
            print_r($send);
            $ruserinfo = $send->getBody();
        } catch (HttpException $ex) {
            echo $ex;
        }

        $arr = json_decode($ruserinfo,true);

        $modhash = $arr['json']['data']['modhash'];
        $reddit_session = $arr['json']['data']['cookie'];

        $post = array(
        'title' => $title,
        'text' => $body,
        'sr' => $this->subreddit,
        'kind' => 'self',
        'uh'=>$modhash,
        'renderstyle'=> 'html',
        );

        $url = "http://www.reddit.com/api/submit";
        $r->addCookies(array("reddit_session" => $reddit_session));
        $r->setUrl($url);
        $r->setPostFields($post);
        try {
            $userinfo = $r->send();
            print_r($userinfo);
        } catch (HttpException $ex) {
            echo $ex;
        }
    }
    
    private function lastN($str, $num)
    {
        $arr = explode(" ", $str);
        $pos = count($arr) -1;
        $out = "";
        for($i = $pos; $i > (count($arr)-1) - $num; $i--)
        {
            $out = " ". $arr[$i] . $out;
        }
        return trim($out);
    }
    
    private function random_string()
    {
        $sth = $this->dbh->prepare("SELECT key FROM pairs ORDER BY RANDOM() LIMIT 1"); //Gets random starting word.
        $sth->execute();
        $data = $sth->fetchColumn();
        $start = $data;
        $output = $start;
        
        $cont = $this->dbh->prepare("SELECT value FROM pairs WHERE lower(key) = ? ORDER BY RANDOM() LIMIT 1");

        do {
            $cont->execute(Array(strtolower($this->lastN($output, 3)))); //Will add to output based on what the last n words were
            $next = $cont->fetchColumn();
            if(empty($next))
                break;
            $output .= " ".trim($next);
            
        } while(true);

        return ucfirst(trim($output));
    }
    
    private function personal_msg($seed)
    {
        $seed = strtolower($seed);
        $seed = preg_replace('/\s{2,}/', ' ', $seed); //Trims 
        $seed = trim($seed);
        
        $seeds = explode(" ", $seed);
        shuffle($seeds);
        
        for($i = 0; $i < count($seeds); $i++) //This function will go through all possible seeds, the first possible one it takes.
        {
            $seed = $seeds[$i]; //Pick a random seed word.
            $sth = $this->dbh->prepare("SELECT key FROM pairs WHERE lower(key) LIKE ? ORDER BY RANDOM() LIMIT 1");
            $sth->execute(array("% ".strtolower($seed)." %"));
            $data = $sth->fetchColumn();
            if(!empty($data))
                break;
        }
        
        if(empty($data)) //If the above loop does not find a seed that works, we are forced to just provide a random string
            return $this->random_string();
            
        $output = $data; //Begin the output chain

        $cont = $this->dbh->prepare("SELECT value FROM pairs WHERE lower(key) LIKE ? ORDER BY RANDOM() LIMIT 1");

        do {
            $cont->execute(Array(strtolower($this->lastN($output, 3)))); //Will add to output based on what the last n words were
            $next = $cont->fetchColumn();
            if(empty($next))
                break;
            $output .= " ".trim($next);
            
        } while(true);

        return ucfirst(trim($output));
    }
	
	// http://stackoverflow.com/a/834355
    private function startsWith($haystack, $needle)
    {
        $length = strlen($needle);
        return (substr($haystack, 0, $length) === $needle);
    }

    private function endsWith($haystack, $needle)
    {
        $length = strlen($needle);
        $start  = $length * -1;
        return (substr($haystack, $start) === $needle);
    }
}
