<?php

class Fitbit
{
    function __construct($rid,$module,$project_id)
    {
        $this->rid = $rid;
        $this->module = $module;
        $this->fetch($project_id);

        // fitbit web api states that refreshing tokens is not rate limited where checking the status of a token is
        // we should verify our authorization status, best way is to go ahead and refresh tokens (if fail, user revoked REDCap's authorization from Fitbit settings)
        if (!empty($this->refresh_token)) {
            $result = $this->refresh_tokens($project_id);
            if (!$result[0]) {
                if (strpos($result[1], "Refresh token invalid") !== false) {
                    // likely that user revoked REDCap's access via their Fitbit application settings
                    $this->reset();
                    $this->revoked_by_user = true;
//                    $this->save();
                }
            }
        }
    }

    private function fetch($project_id) {
        $q = $this->module->query("SELECT value FROM redcap_data WHERE project_id=? AND field_name=? AND record=?",[$project_id,'fitbit_steps_auth',$this->rid]);
        $row = $q->fetch_assoc();
        if (!empty($row['value'])) {
            $data = json_decode($row['value']);
            // assimilate property values
            foreach ($data as $property => $value) {
                $this->$property = $value;
            }
        }
    }

    function make_auth_link($module) {
        // make anti-csrf token, store in db
        if (empty($this->nonce))
            $this->nonce = generateRandomHash(64);

        // make record id hash value
        $rhash = md5("hpst" . $this->rid);

        $fitbit_api = $this->get_credentials($module);

        $fitbit_auth_url = "https://www.fitbit.com/oauth2/authorize";
        // response_type
        $fitbit_auth_url .= "?response_type=code";
        // client_id
        $fitbit_auth_url .= "&client_id=" . rawurlencode($fitbit_api->client_id);
        // scope
        $fitbit_auth_url .= "&scope=" . rawurlencode("activity");
        // redirect_uri
        $fitbit_auth_url .= "&redirect_uri=" . rawurlencode($fitbit_api->redirect_uri);
        // state
        $fitbit_auth_url .= "&state=" . rawurlencode($this->nonce . " " . $rhash);
        // prompt
        $fitbit_auth_url .= "&prompt=" . rawurlencode("login consent");

        return $fitbit_auth_url;
    }

    function link_account($project_id) {
        // this func should be called when user is redirected by fitbit api to fitbit/redirected.php (with goodies in _GET)

        // make sure we have auth_code and state vars we need
        if (!isset($_GET['code']) or !isset($_GET['state'])) {
            return array(false, "missing _GET[code] or _GET[status]");
        }

        // POST via curl to authorize and get access and refresh token
        $ch = curl_init();
        $url = "https://api.fitbit.com/oauth2/token";
        $credentials = $this->get_credentials($this->module);
        $secrets = base64_encode($credentials->client_id . ":" . $credentials->client_secret);
        $post_params = http_build_query([
            "code" => filter_var($_GET['code'], FILTER_SANITIZE_STRING),
            "grant_type" => "authorization_code",
            "client_id" => $credentials->client_id,
            "redirect_uri" => $credentials->redirect_uri,
            "state" => filter_var($_GET['state'], FILTER_SANITIZE_STRING)
        ]);

        // set
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_params);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Basic $secrets",
            "Content-Type: application/x-www-form-urlencoded"
        ]);

        // execute
        $output = curl_exec($ch);
        if (!empty(curl_error($ch))) {
            return array(false, "Fitbit Web API response: $output -- curl error: " . curl_error($ch));
        }
        curl_close($ch);

        $result = json_decode($output, true);
        if (empty($result["access_token"])) {
            return array(false, "Fitbit Web API response: $output");
        }

        // this is to remove any revoke_timestamp, revoked_by_user, etc
        $this->reset();

        // assimilate property values (like access_token and refresh_token) and save to db
        foreach ($result as $property => $value) {
            $this->$property = $value;
        }
        $this->auth_timestamp = time();
        $this->save($project_id);

        return array(true);
    }

    function refresh_tokens($project_id) {;
        $ch = curl_init();
        $url = "https://api.fitbit.com/oauth2/token";
        $post_params = http_build_query([
            "grant_type" => "refresh_token",
            "refresh_token" => $this->refresh_token
        ]);

        // set
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_params);

        $fitbit_api = $this->get_credentials($this->module);
        $secrets = base64_encode($fitbit_api->client_id . ":" . $fitbit_api->client_secret);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Basic $secrets",
            "Content-Type: application/x-www-form-urlencoded"
        ]);

        // execute
        $output = json_decode(curl_exec($ch));
        if (!empty(curl_error($ch))) {
            return array(false, curl_error($ch));
        }
        curl_close($ch);

        if (empty($output->access_token)) {
            // if refresh token invalid, set revoked_by_user
            return array(false, json_encode($output));
        }

        // assimilate property values from successful request (it has new access and refresh tokens etc)
        foreach ($output as $property => $value) {
            $this->$property = $value;
        }
        $result = $this->save($project_id);
        $result = "ok";
        return $result;
    }

    private function get_credentials($module) {
        if (file_exists("/Applications/XAMPP/htdocs/modules/health-plus-steps-tracker_v1.0.0/health_plus_steps_tracker_fitbit.txt")) {
            $filename = "/Applications/XAMPP/htdocs/modules/health-plus-steps-tracker_v1.0.0/health_plus_steps_tracker_fitbit.txt";
        }else{
            $filename = "/app001/credentials/health_plus_steps_tracker_fitbit.txt";
        }
        $credentials = json_decode(file_get_contents($filename));
        $credentials->redirect_uri = $module->getUrl("fitbit_users.php?page=fitbit_users&NOAUTH");
        return $credentials;
    }

    public function save($project_id) {
        // this function take current fitbit state and save applicable fields/data to db (for user: $this->rid)
        $value = clone $this;
        unset($value->module);
        $value = json_encode($value);

        // see if we should insert, prepare values string
        $rid = $this->rid;
        $Proj = new \Project($project_id);
        $event_id = $Proj->firstEventId;

        $q = $this->module->query("SELECT value FROM redcap_data WHERE project_id=? AND field_name=? AND record=?",[$project_id,'fitbit_steps_auth',$rid]);
        if (empty($q->fetch_assoc())) {
            $q = $this->module->query("INSERT INTO redcap_data (project_id, record, event_id, field_name, value) VALUES (?,?,?,?,?)",[$project_id,$rid,$event_id,'fitbit_steps_auth',$value]);
        }else{
            $q = $this->module->query("UPDATE redcap_data SET value = ? WHERE project_id=? and field_name=? and record=?",[$value,$project_id,'fitbit_steps_auth',$rid]);
        }

        return array(true);
    }

    public function reset() {
        // remove all property values except $this->rid and $this->module
        foreach ($this as $property => $value) {
            if ($property != "rid" && $property != "module") {
                unset($this->$property);
            }
        }
    }

    function get_activity($datetime) {
        // create cURL handle
        $ch = curl_init();
        $user_id = $this->user_id;
        $url = "https://api.fitbit.com/1/user/$user_id/activities/steps/date/$datetime/1d.json";

        // set curl options to get fairly active minutes
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer " . $this->access_token,
            "Content-Type: application/x-www-form-urlencoded",
            "Accept-Language: en_US"
        ]);
        $output = curl_exec($ch);	// execute
        if (!empty(curl_error($ch))) {
            return array(false, "Fitbit Web API response: $output -- curl error: " . curl_error($ch));
        }
        // close and decode results
        curl_close($ch);

        $output = json_decode($output);

        // get number of steps
        $steps = $output->{'activities-steps'}{0}->value;

        return array(true, $steps);
    }
}
?>